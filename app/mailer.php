<?php
// Outgoing email (BILLING.md "Email"). PHPMailer lives in lib/phpmailer and is loaded only when a message is built.
// SMTP settings: .env SMTP_HOST/PORT/USER/PASS/SECURE; when SMTP_HOST is empty and MAIL_SETTINGS_FROM_DB=1, the shared
// hotel table email_settings (smtp_* keys only; its from-name, BCC and preview flags belong to the hotel and are ignored).
// From is always MAIL_FROM_EMAIL / MAIL_FROM_NAME. MAIL_TEST_TO set => every email goes ONLY there.
// The SMTP password is never printed, logged or returned. Including this file opens no connection.

if (!defined('MAIL_LOGO_CID')) {
    define('MAIL_LOGO_CID', 'pmit-logo'); // shared with app/email_templates.php
}
const MAIL_TIMEOUT_SECONDS = 20;

/** SMTP + sender settings. Keys: host, port, user, pass, secure ('ssl'|'tls'|''), from_email, from_name, test_to (array), source. */
function mail_config(bool $refresh = false): array
{
    static $cfg = null;
    if ($cfg !== null && !$refresh) {
        return $cfg;
    }
    $c = [
        'host' => trim((string)env('SMTP_HOST', '')),
        'port' => (int)env('SMTP_PORT', 0),
        'user' => trim((string)env('SMTP_USER', '')),
        'pass' => (string)env('SMTP_PASS', ''),
        'secure' => strtolower(trim((string)env('SMTP_SECURE', ''))),
        'source' => 'env',
    ];
    if ($c['host'] === '') {
        $c['source'] = 'none';
        if ((string)env('MAIL_SETTINGS_FROM_DB', '') === '1') {
            $db = mail_db_settings();
            if (($db['smtp_host'] ?? '') !== '') {
                $c = [
                    'host' => trim($db['smtp_host']),
                    'port' => (int)($db['smtp_port'] ?? 0),
                    'user' => trim($db['smtp_username'] ?? ''),
                    'pass' => (string)($db['smtp_password'] ?? ''),
                    'secure' => strtolower(trim($db['smtp_secure'] ?? '')),
                    'source' => 'db',
                ];
            }
        }
    }
    $c['secure'] = mail_secure_for($c['port'], $c['secure']);
    if ($c['port'] <= 0) {
        $c['port'] = $c['secure'] === 'ssl' ? 465 : 587;
    }
    $c['from_email'] = trim((string)env('MAIL_FROM_EMAIL', ''));
    if ($c['from_email'] === '') {
        $c['from_email'] = $c['user'];
    }
    $c['from_name'] = trim((string)env('MAIL_FROM_NAME', 'ProManaged IT'));
    $c['test_raw'] = trim((string)env('MAIL_TEST_TO', ''));
    $c['test_to'] = mail_split($c['test_raw']);
    $cfg = $c;
    return $cfg;
}

/** Pure: port 465 => ssl, 587 => tls; an explicit ssl/tls wins; anything else => none. */
function mail_secure_for(int $port, string $secure): string
{
    $secure = strtolower(trim($secure));
    if ($secure === 'ssl' || $secure === 'smtps') {
        return 'ssl';
    }
    if ($secure === 'tls' || $secure === 'starttls') {
        return 'tls';
    }
    if ($port === 465) {
        return 'ssl';
    }
    if ($port === 587) {
        return 'tls';
    }
    return '';
}

/** Read-only: the smtp_* rows of the shared email_settings table (encrypted values decrypted in MySQL). */
function mail_db_settings(): array
{
    $keys = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_secure'];
    $out = [];
    try {
        $pdo = db();
        $st = $pdo->prepare('SELECT setting_key, setting_value, is_encrypted FROM email_settings WHERE setting_key IN (?, ?, ?, ?, ?)');
        $st->execute($keys);
        foreach ($st->fetchAll() as $r) {
            $v = (string)$r['setting_value'];
            if ((int)$r['is_encrypted'] === 1 && $v !== '') {
                try {
                    $d = $pdo->prepare('SELECT decrypt_setting(?) AS v');
                    $d->execute([$v]);
                    $dv = $d->fetchColumn();
                    if ($dv !== false && $dv !== null && $dv !== '') {
                        $v = (string)$dv;
                    }
                    // Empty/failed decrypt: keep the stored value (same fallback as the hotel).
                } catch (Throwable $e) {
                    error_log('mail: decrypt_setting unavailable');
                }
            }
            $out[(string)$r['setting_key']] = $v;
        }
    } catch (Throwable $e) {
        error_log('mail: email_settings could not be read: ' . get_class($e));
    }
    return $out;
}

/** For the Settings page: where SMTP comes from, host and user only (never the password). */
function mail_status(): array
{
    $c = mail_config();
    return [
        'configured' => $c['host'] !== '',
        'source' => $c['source'],
        'host' => $c['host'],
        'port' => $c['port'],
        'secure' => $c['secure'],
        'user' => $c['user'],
        'from_email' => $c['from_email'],
        'from_name' => $c['from_name'],
        'test_to' => $c['test_to'],
    ];
}

/** Pure: "a@b.com, c@d.com" or an array => unique, trimmed, valid addresses. */
function mail_split($list): array
{
    if (!is_array($list)) {
        $list = preg_split('/[\s,;]+/', (string)$list) ?: [];
    }
    $out = [];
    foreach ($list as $a) {
        $a = trim((string)$a);
        if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n]/', $a)) {
            $k = strtolower($a);
            if (!isset($out[$k])) {
                $out[$k] = $a;
            }
        }
    }
    return array_values($out);
}

/**
 * Pure: who actually receives the email. With $testTo non-empty, ONLY the test address(es) receive it, cc is dropped and
 * the subject names the original recipients: "[TEST to a@b, c@d] Subject".
 * Returns ['to'=>[], 'cc'=>[], 'subject'=>string, 'test'=>bool, 'intended'=>[original to + cc]].
 */
function mail_recipients(array $to, array $cc, string $subject, array $testTo): array
{
    $to = mail_split($to);
    $cc = array_values(array_udiff(mail_split($cc), $to, 'strcasecmp'));
    $subject = trim(preg_replace('/[\r\n\t]+/', ' ', $subject));
    $intended = array_merge($to, $cc);
    $testTo = mail_split($testTo);
    if ($testTo) {
        $names = $intended ? implode(', ', $intended) : 'nobody';
        return ['to' => $testTo, 'cc' => [], 'subject' => '[TEST to ' . $names . '] ' . $subject, 'test' => true, 'intended' => $intended];
    }
    return ['to' => $to, 'cc' => $cc, 'subject' => $subject, 'test' => false, 'intended' => $intended];
}

function mail_load_phpmailer(): void
{
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer', false)) {
        $dir = dirname(__DIR__) . '/lib/phpmailer/';
        require_once $dir . 'Exception.php';
        require_once $dir . 'PHPMailer.php';
        require_once $dir . 'SMTP.php';
    }
}

function mail_logo_path(): string
{
    return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__)) . '/public/assets/logo.png';
}

/**
 * Builds (does not send) the PHPMailer message. $r = mail_recipients(...) result. No network.
 * @return \PHPMailer\PHPMailer\PHPMailer
 */
function mail_prepare(array $m, array $cfg, array $r)
{
    mail_load_phpmailer();
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = $cfg['host'];
    $mail->Port = (int)$cfg['port'];
    $mail->SMTPAuth = $cfg['user'] !== '';
    $mail->Username = $cfg['user'];
    $mail->Password = $cfg['pass'];
    $mail->SMTPSecure = $cfg['secure'];
    $mail->SMTPAutoTLS = $cfg['secure'] !== 'ssl';
    $mail->SMTPDebug = 0;
    $mail->Debugoutput = function () {
    };
    $mail->Timeout = MAIL_TIMEOUT_SECONDS;
    $mail->getSMTPInstance()->Timelimit = MAIL_TIMEOUT_SECONDS;
    $mail->XMailer = ' ';

    $mail->setFrom($cfg['from_email'], $cfg['from_name']);
    if ($cfg['user'] !== '' && filter_var($cfg['user'], FILTER_VALIDATE_EMAIL)) {
        $mail->Sender = $cfg['user'];
    }
    $replyTo = mail_split((string)($m['reply_to'] ?? ''));
    $mail->addReplyTo($replyTo ? $replyTo[0] : $cfg['from_email'], $cfg['from_name']);
    foreach ($r['to'] as $a) {
        $mail->addAddress($a);
    }
    foreach ($r['cc'] as $a) {
        $mail->addCC($a);
    }
    $mail->Subject = $r['subject'];
    $mail->isHTML(true);
    $mail->Body = (string)($m['html'] ?? '');
    $mail->AltBody = (string)($m['text'] ?? '');
    $logo = mail_logo_path();
    if (is_readable($logo) && strpos($mail->Body, 'cid:' . MAIL_LOGO_CID) !== false) {
        $mail->addEmbeddedImage($logo, MAIL_LOGO_CID, 'logo.png', 'base64', 'image/png');
    }
    foreach (($m['attachments'] ?? []) as $att) {
        if (!isset($att['bytes']) || (string)$att['bytes'] === '') {
            continue;
        }
        $name = preg_replace('/[\r\n\/\\\\]+/', ' ', (string)($att['name'] ?? 'attachment.pdf'));
        $mail->addStringAttachment((string)$att['bytes'], $name, 'base64', (string)($att['mime'] ?? 'application/pdf'));
    }
    return $mail;
}

/** Pure: a sender error in plain words, with the password masked as a backstop. */
function mail_plain_error(string $raw, array $cfg): string
{
    if ($cfg['pass'] !== '') {
        $raw = str_replace($cfg['pass'], '********', $raw);
    }
    $l = strtolower($raw);
    $where = $cfg['host'] . ':' . $cfg['port'];
    if (strpos($l, 'authenticate') !== false || strpos($l, 'authentication') !== false || strpos($l, '535') !== false) {
        $msg = 'The mail server (' . $where . ') refused the username or password.';
    } elseif (strpos($l, 'connect') !== false || strpos($l, 'timed out') !== false || strpos($l, 'timeout') !== false) {
        $msg = 'Could not reach the mail server (' . $where . '). Check the host, port and security setting, or try again later.';
    } elseif (strpos($l, 'invalid address') !== false || strpos($l, 'recipient') !== false || strpos($l, 'all recipients') !== false) {
        $msg = 'The mail server would not accept the recipient address.';
    } elseif (strpos($l, 'sender') !== false || strpos($l, 'from address') !== false) {
        $msg = 'The mail server would not accept the sender address (' . $cfg['from_email'] . ').';
    } elseif (strpos($l, 'data not accepted') !== false) {
        $msg = 'The mail server rejected the message.';
    } else {
        $msg = 'The email could not be sent.';
    }
    $detail = trim(preg_replace('/\s+/', ' ', strip_tags($raw)));
    if ($detail !== '') {
        $msg .= ' (' . substr($detail, 0, 200) . ')';
    }
    return $msg;
}

/**
 * Sends one email. $m: to[], cc[], subject, html, text, attachments [['name','bytes','mime']], reply_to.
 * Returns ['ok'=>bool, 'error'=>string, 'to'=>actual recipients, 'cc'=>actual cc, 'test'=>bool, 'subject'=>actual subject,
 *          'intended'=>original to + cc]. Writes no log: callers log to cp_email_log.
 */
function mail_send(array $m): array
{
    $cfg = mail_config();
    if (($cfg['test_raw'] ?? '') !== '' && !$cfg['test_to']) {
        // test mode is ON but its address does not read as an email: never fall through to the real customer
        return ['ok' => false, 'error' => 'MAIL_TEST_TO is set in .env but is not a plain email address (for example you@example.com), so nothing was sent.', 'to' => []];
    }
    $r = mail_recipients((array)($m['to'] ?? []), (array)($m['cc'] ?? []), (string)($m['subject'] ?? ''), $cfg['test_to']);
    $res = ['ok' => false, 'error' => '', 'to' => $r['to'], 'cc' => $r['cc'], 'test' => $r['test'], 'subject' => $r['subject'], 'intended' => $r['intended']];
    if ($cfg['host'] === '') {
        $res['error'] = 'Email is not set up: there is no SMTP server in .env or in the shared email settings.';
        return $res;
    }
    if ($cfg['from_email'] === '' || !filter_var($cfg['from_email'], FILTER_VALIDATE_EMAIL)) {
        $res['error'] = 'Email is not set up: MAIL_FROM_EMAIL in .env is missing or not a valid address.';
        return $res;
    }
    if (!$r['to']) {
        $res['error'] = 'There is no valid recipient email address.';
        return $res;
    }
    if ($r['subject'] === '') {
        $res['error'] = 'The email has no subject.';
        return $res;
    }
    try {
        $mail = mail_prepare($m, $cfg, $r);
        $mail->send();
        $res['ok'] = true;
    } catch (Throwable $e) {
        $raw = isset($mail) && $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
        $res['error'] = mail_plain_error((string)$raw, $cfg);
        error_log('mail: send failed: ' . $res['error']);
    } finally {
        if (isset($mail)) {
            $mail->smtpClose();
        }
    }
    return $res;
}
