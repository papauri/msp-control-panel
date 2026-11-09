<?php
// "Forgot your password?" by email (owner 2026-11-09). The link only replaces the PASSWORD: finishing still needs a
// current 6-digit code, so someone who gets into an admin's mailbox cannot get in with it. A lost phone is still a
// reset by another admin on the Users page, or php scripts/reset-2fa.php.
//
// - On only when email can be sent and APP_URL is a proper https address (the link is never built from the Host header).
// - Only for accounts that have finished setting up (they have 2-step codes). An account still waiting for its first
//   sign-in gets nothing: its password alone opens the setup step, so a link would hand it to whoever reads the email.
// - Same answer for every email, known or not. Rate-limited per internet address and per email.
// - The link's secret is 32 random bytes; only its SHA-256 is stored. It lasts PWRESET_TTL_MINUTES, works once, dies
//   when a newer link is asked for, when the password is changed or reset any other way, and after
//   PWRESET_MAX_TRIES wrong codes.
// - Success signs in, ends every other session (auth_fp), is audited, and emails a "your password was changed" notice.

const PWRESET_TTL_MINUTES = 30;
const PWRESET_MAX_TRIES = 5;          // wrong 6-digit codes on one link before it stops working
const PWRESET_PER_IP_PER_HOUR = 5;    // link requests from one internet address
const PWRESET_PER_EMAIL_PER_HOUR = 3; // link requests for one email (known or not)

function pwreset_load_mailer(): void
{
    require_once __DIR__ . '/mailer.php';
}

/** Pure: the panel's public base address from APP_URL, or '' when it is not usable for a link (not https, or junk). */
function pwreset_base_url(string $appUrl): string
{
    $u = trim($appUrl);
    if ($u === '' || preg_match('/[\s"\'<>\\\\]/', $u)) {
        return '';
    }
    $p = parse_url($u);
    if (!is_array($p) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
        return '';
    }
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    $local = in_array(strtolower((string)$p['host']), ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
        return '';
    }
    return rtrim($u, '/');
}

/** Pure: the link in the email. */
function pwreset_link(string $base, string $token): string
{
    return $base . '/reset.php?t=' . $token;
}

/** Pure: a link secret as it appears in the URL (64 lower-case hex characters). */
function pwreset_token_ok(string $t): bool
{
    return strlen($t) === 64 && ctype_xdigit($t) && strtolower($t) === $t;
}

/** Whether the "Forgot your password?" link is on: email is set up and APP_URL is https. Never throws. */
function pwreset_available(): bool
{
    try {
        if (pwreset_base_url((string)env('APP_URL', '')) === '') {
            return false;
        }
        pwreset_load_mailer();
        $c = mail_config();
        return $c['host'] !== '' && filter_var($c['from_email'], FILTER_VALIDATE_EMAIL) !== false
            && !(($c['test_raw'] ?? '') !== '' && !$c['test_to']);
    } catch (Throwable $e) {
        error_log('pwreset_available: ' . get_class($e));
        return false;
    }
}

/** Why it is off, in words (Settings page); '' when it is on. */
function pwreset_off_reason(): string
{
    if (pwreset_base_url((string)env('APP_URL', '')) === '') {
        return 'APP_URL in .env must be the panel\'s https address (for example https://licence.example.com).';
    }
    return pwreset_available() ? '' : 'Email is not set up (see above).';
}

/**
 * A "Forgot your password?" request. Always looks the same to the person asking. Returns the email to send (pass it to
 * pwreset_send_link after the page has been sent), or null when nothing is to be sent: unknown email, an account not set
 * up yet, too many requests, or the feature is off.
 */
function pwreset_request(PDO $pdo, string $email): ?array
{
    if (!pwreset_available()) {
        return null;
    }
    $ip = client_ip();
    $email = strtolower(trim($email));
    if ($email === '' || strlen($email) > 190) {
        return null;
    }
    if (!rate_limit_hit($pdo, 'pwreset-ip:' . $ip, PWRESET_PER_IP_PER_HOUR, 3600)) {
        if (rate_limit_hit($pdo, 'pwreset-ip-noted:' . $ip, 1, 3600)) {
            audit_log($pdo, null, 'password_reset_blocked', null, null, ['limit' => 'too many reset requests from this address']);
        }
        return null;
    }
    if (!rate_limit_hit($pdo, 'pwreset-email:' . hash('sha256', $email), PWRESET_PER_EMAIL_PER_HOUR, 3600)) {
        return null;
    }

    $st = $pdo->prepare("SELECT id, email, password_hash FROM cp_admins WHERE email = ? AND totp_secret <> ''");
    $st->execute([$email]);
    $admin = $st->fetch();
    if (!$admin) {
        audit_log($pdo, null, 'password_reset_requested', null, null, ['account' => 'no set-up account with that email']);
        return null;
    }
    $id = (int)$admin['id'];
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('DELETE FROM cp_password_resets WHERE expires_at < NOW() - INTERVAL 2 DAY')->execute();
    $pdo->prepare('UPDATE cp_password_resets SET used_at = NOW() WHERE admin_id = ? AND used_at IS NULL')->execute([$id]); // only the newest link works
    $pdo->prepare('INSERT INTO cp_password_resets (admin_id, token_hash, pw_fp, ip, expires_at)
                   VALUES (?, ?, ?, ?, NOW() + INTERVAL ' . (int)PWRESET_TTL_MINUTES . ' MINUTE)')
        ->execute([$id, hash('sha256', $token), hash('sha256', (string)$admin['password_hash']), $ip]);
    audit_log($pdo, $id, 'password_reset_requested', null, null, null);
    return [
        'admin_id' => $id,
        'to' => (string)$admin['email'],
        'link' => pwreset_link(pwreset_base_url((string)env('APP_URL', '')), $token),
        'ip' => $ip,
    ];
}

/** The live link for $token joined to its account, or null (unknown, used, expired, or the account changed since). */
function pwreset_lookup(PDO $pdo, string $token): ?array
{
    if (!pwreset_token_ok($token)) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT r.id, r.admin_id, r.pw_fp, a.email, a.password_hash, a.totp_secret
           FROM cp_password_resets r JOIN cp_admins a ON a.id = r.admin_id
          WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()'
    );
    $st->execute([hash('sha256', $token)]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    if ((string)$row['totp_secret'] === '' || !hash_equals((string)$row['pw_fp'], hash('sha256', (string)$row['password_hash']))) {
        // reset by an admin, or the password changed another way, since the link was sent: the link is void
        $pdo->prepare('UPDATE cp_password_resets SET used_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
        return null;
    }
    return $row;
}

/**
 * Sets the new password from a link. Needs a current 6-digit code (single-use, like sign-in). Signs in on success.
 * Returns ['error' => '' | words, 'expired' => bool, 'notice' => email to send after the page (on success)].
 */
function pwreset_finish(PDO $pdo, string $token, string $code, string $pw, string $repeat): array
{
    $expired = ['error' => 'This link has expired or has already been used. Ask for a new one.', 'expired' => true, 'notice' => null];
    $row = pwreset_lookup($pdo, $token);
    if ($row === null) {
        return $expired;
    }
    $id = (int)$row['admin_id'];
    $email = (string)$row['email'];
    $failBucket = auth_fail_bucket(strtolower($email), client_ip()); // shared with sign-in: 5 wrong codes per 15 min
    if (rate_limit_count($pdo, $failBucket, AUTH_LOCK_MINUTES * 60) >= AUTH_MAX_FAILURES) {
        return ['error' => 'Too many wrong tries from here. Wait 15 minutes and try again.', 'expired' => false, 'notice' => null];
    }
    if (($p = auth_password_problem($pw, $repeat, $email)) !== '') {
        return ['error' => $p, 'expired' => false, 'notice' => null];
    }
    $secret = auth_totp_secret((string)$row['totp_secret']);
    $step = ($secret !== null && $secret !== '') ? totp_verify($secret, $code, time(), 1) : false;
    if ($step === false) {
        rate_limit_add($pdo, $failBucket, AUTH_LOCK_MINUTES * 60);
        // MySQL applies SET left to right: used_at sees the new attempts count
        $pdo->prepare('UPDATE cp_password_resets SET attempts = attempts + 1, used_at = IF(attempts >= ?, NOW(), used_at) WHERE id = ?')
            ->execute([PWRESET_MAX_TRIES, (int)$row['id']]);
        audit_log($pdo, $id, 'password_reset_failed', null, null, ['reason' => 'wrong 6-digit code']);
        usleep(random_int(150000, 350000));
        return ['error' => "That code is not right. Check your phone's clock is automatic and type the newest code.", 'expired' => false, 'notice' => null];
    }
    if (password_verify($pw, (string)$row['password_hash'])) {
        return ['error' => 'That is your current password. Choose a new one.', 'expired' => false, 'notice' => null];
    }

    $hash = auth_hash_password($pw);
    $storeNow = auth_totp_store_value((string)$row['totp_secret'], $secret);
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare('UPDATE cp_password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL AND expires_at > NOW()');
        $claim->execute([(int)$row['id']]);
        if ($claim->rowCount() !== 1) {
            $pdo->rollBack();
            return $expired;
        }
        $up = $pdo->prepare(
            'UPDATE cp_admins SET password_hash = ?, totp_secret = ?, totp_last_step = ?, failed_count = 0, locked_until = NULL, last_login_at = NOW()
             WHERE id = ? AND password_hash = ? AND totp_secret = ? AND (totp_last_step IS NULL OR totp_last_step < ?)'
        );
        $up->execute([$hash, $storeNow, $step, $id, (string)$row['password_hash'], (string)$row['totp_secret'], $step]);
        if ($up->rowCount() !== 1) {
            $pdo->rollBack();
            return ['error' => 'That code has already been used. Wait for the next code and try again.', 'expired' => false, 'notice' => null];
        }
        $pdo->prepare('UPDATE cp_password_resets SET used_at = NOW() WHERE admin_id = ? AND used_at IS NULL')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    rate_limit_clear($pdo, $failBucket);
    auth_start_session($id, $hash, $storeNow);
    audit_log($pdo, $id, 'password_reset_email', null, null, null);
    return ['error' => '', 'expired' => false, 'notice' => ['admin_id' => $id, 'to' => $email, 'ip' => client_ip()]];
}

// ---------------------------------------------------------------- the emails (pure builders, then sending)

/** Pure: a short branded security email. $paras / $after are plain text (before / after the button); $button = [label, url] or null. */
function pwreset_email_html(string $title, array $paras, ?array $button, array $after = []): string
{
    $h = static function ($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $font = 'font-family:Arial,Helvetica,sans-serif;';
    $para = static function (string $p) use ($h, $font): string {
        return '<p style="' . $font . 'font-size:14px;line-height:1.6;color:#222222;margin:0 0 14px 0;">' . $h($p) . '</p>';
    };
    $body = implode('', array_map($para, $paras));
    if ($button !== null) {
        $body .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 18px 0;"><tr>'
            . '<td style="background:#1D4F91;border-radius:4px;"><a href="' . $h($button[1]) . '" style="' . $font
            . 'display:inline-block;padding:11px 20px;font-size:14px;font-weight:bold;color:#FFFFFF;text-decoration:none;">' . $h($button[0]) . '</a></td>'
            . '</tr></table>'
            . '<p style="' . $font . 'font-size:12px;line-height:1.5;color:#666666;margin:0 0 14px 0;">If the button does not work, copy this address into your browser:<br>'
            . '<span style="word-break:break-all;">' . $h($button[1]) . '</span></p>';
    }
    $body .= implode('', array_map($para, $after));
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
        . $h($title) . '</title></head><body style="margin:0;padding:0;background:#F2F2F2;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F2F2F2;"><tr><td align="center" style="padding:24px 10px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;width:100%;background:#FFFFFF;border:1px solid #E0E0E0;">'
        . '<tr><td style="padding:24px 28px 6px 28px;"><img src="cid:' . (defined('MAIL_LOGO_CID') ? MAIL_LOGO_CID : 'pmit-logo') . '" width="72" alt="" style="display:block;border:0;width:72px;height:auto;"></td></tr>'
        . '<tr><td style="padding:10px 28px 0 28px;' . $font . 'font-size:20px;font-weight:bold;color:#1B3A5C;">' . $h($title) . '</td></tr>'
        . '<tr><td style="padding:16px 28px 10px 28px;">' . $body . '</td></tr>'
        . '<tr><td style="background:#D40000;height:4px;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Pure: the "reset your password" email. */
function pwreset_link_email(string $link, string $ip): array
{
    $title = 'Reset your password';
    $paras = [
        'Someone (hopefully you) asked to reset the password for your Licence panel account, from internet address ' . $ip . '.',
        'Open the link below within ' . PWRESET_TTL_MINUTES . ' minutes. You will choose a new password and type a 6-digit code from your authenticator app. The link works once.',
    ];
    $after = 'Did not ask for this? Ignore this email: your password stays the same, and nobody can use this link without your 6-digit codes.';
    return [
        'subject' => 'Reset your Licence panel password',
        'html' => pwreset_email_html($title, $paras, ['Choose a new password', $link], [$after]),
        'text' => $title . "\n\n" . implode("\n\n", $paras) . "\n\n" . $link . "\n\n" . $after . "\n",
    ];
}

/** Pure: the "your password was changed" notice. */
function pwreset_notice_email(string $when, string $ip): array
{
    $title = 'Your password was changed';
    $paras = [
        'The password for your Licence panel account was changed with a reset link on ' . $when . ', from internet address ' . $ip . '. Every other browser signed in as you has been signed out.',
        'If this was you, there is nothing to do.',
        'If it was NOT you: someone has your email and your 6-digit codes. Ask another admin to reset your account on the Users page straight away, and tell them it was not you.',
    ];
    return [
        'subject' => 'Your Licence panel password was changed',
        'html' => pwreset_email_html($title, $paras, null),
        'text' => $title . "\n\n" . implode("\n\n", $paras) . "\n",
    ];
}

/** Sends one security email and records the attempt in cp_email_log (never the link). */
function pwreset_send(PDO $pdo, int $adminId, string $kind, string $to, array $msg): bool
{
    pwreset_load_mailer();
    $res = mail_send(['to' => [$to], 'subject' => $msg['subject'], 'html' => $msg['html'], 'text' => $msg['text']]);
    try {
        $pdo->prepare('INSERT INTO cp_email_log (kind, to_email, subject, status, error, admin_id) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([
                $kind,
                substr(implode(', ', (array)($res['to'] ?? [$to])), 0, 500),
                substr((string)($res['subject'] ?? $msg['subject']), 0, 255),
                !empty($res['ok']) ? (!empty($res['test']) ? 'test' : 'sent') : 'failed',
                !empty($res['ok']) ? null : substr((string)($res['error'] ?? ''), 0, 500),
                $adminId,
            ]);
    } catch (Throwable $e) {
        error_log('pwreset: email log: ' . get_class($e));
    }
    return !empty($res['ok']);
}

function pwreset_send_link(PDO $pdo, array $job): void
{
    pwreset_send($pdo, (int)$job['admin_id'], 'password_reset', $job['to'], pwreset_link_email($job['link'], $job['ip']));
}

function pwreset_send_notice(PDO $pdo, array $n): void
{
    pwreset_send($pdo, (int)$n['admin_id'], 'password_changed', $n['to'], pwreset_notice_email(date('j M Y \a\t H:i'), $n['ip']));
}
