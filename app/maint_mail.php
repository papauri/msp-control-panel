<?php
// Email a business's administrators about a maintenance window (owner 2026-11-24): "Have an option to send email of the
// upcoming scheduled maintenance to the admin user accounts of the hotel, just to let them know in advance or if any
// issues. Have a few drafts ready."
//
// Six built-in drafts, each editable on the Maintenance page (cp_settings maint_mail_<key>, JSON {subject, body}; deleting
// the row reverts to the built-in draft). Placeholders are filled from the window in ONE pass (strtr), so a hotel name or
// message that itself contains "{message}" is never filled again. The filled text is plain; the HTML is built from it with
// every character escaped (em_e / em_nl2br), never from pre-escaped values.
// Recipients are only ever the business's own ACTIVE synced staff (cp_hms_staff) with a valid email, chosen by pms_user_id:
// a free-typed address can never be sent to. One email per person, each logged in cp_email_log (kind 'maintenance',
// attachment = "maintenance #<window id> <draft>" as the link to the window), plus one audit entry per send.
// The mailer and the email templates are loaded only when an email is built (as app/pwreset.php does).

const MAINT_MAIL_CAP = 50;          // people per send
const MAINT_MAIL_REPEAT = 60;       // seconds: the same draft for the same window again needs "send again"
const MAINT_MAIL_SUBJECT_MAX = 200;
const MAINT_MAIL_BODY_MAX = 4000;     // the text the owner types (placeholders still in)
const MAINT_MAIL_FINAL_MAX = 20000;  // the message once filled in (a long window message and business name included)
const MAINT_MAIL_RECENT_DAYS = 7;   // an ended window can still be emailed about for this many days
const MAINT_MAIL_SETTING = 'maint_mail_';

/** @return array<string,array{label:string,title:string,subject:string,body:string}> the built-in drafts, in page order */
function maint_mail_drafts(): array
{
    return [
        'advance' => [
            'label' => 'Advance notice',
            'title' => 'Scheduled maintenance',
            'subject' => 'Scheduled maintenance for {hotel} on {date}',
            'body' => "Hello,\n\nWe will carry out scheduled maintenance on your system on {date}, from {from} to {to} ({zone} time).\n\n"
                . "What goes offline: {what goes offline}.\n\n{message}\n\n"
                . "Please let your team know in advance. If this time does not suit you, or you have any questions, reply to this email or contact us at {contact}.",
        ],
        'reminder' => [
            'label' => 'Reminder (coming up soon)',
            'title' => 'Maintenance reminder',
            'subject' => 'Reminder: maintenance for {hotel} on {date} at {from}',
            'body' => "Hello,\n\nA quick reminder: our scheduled maintenance on your system starts on {date} at {from} and runs until {to} ({zone} time).\n\n"
                . "What goes offline: {what goes offline}.\n\n"
                . "Please make sure any work in progress is saved before {from}. If you have any questions, contact us at {contact}.",
        ],
        'starting' => [
            'label' => 'Starting now',
            'title' => 'Maintenance starting',
            'subject' => 'Maintenance starting now for {hotel}',
            'body' => "Hello,\n\nOur scheduled maintenance on your system is starting now and runs until {to} ({zone} time).\n\n"
                . "What goes offline: {what goes offline}. It comes back on by itself when the work is done.\n\n{message}\n\n"
                . "If something urgent comes up, contact us at {contact}.",
        ],
        'changed' => [
            'label' => 'Times changed / extended',
            'title' => 'Maintenance update',
            'subject' => 'Maintenance times changed for {hotel}',
            'body' => "Hello,\n\nThe times of our maintenance on your system have changed. It now runs on {date}, from {from} to {to} ({zone} time).\n\n"
                . "What goes offline: {what goes offline}.\n\n"
                . "Sorry for any trouble this causes. If you have any questions, contact us at {contact}.",
        ],
        'back_online' => [
            'label' => 'Back online (work complete)',
            'title' => 'Back online',
            'subject' => '{hotel} is back online',
            'body' => "Hello,\n\nThe maintenance on your system is complete and everything is back online. The work finished at {to} ({zone} time).\n\n"
                . "If anything does not work as before, please tell us straight away at {contact} and we will look at it.",
        ],
        'problem' => [
            'label' => 'Problem during the work',
            'title' => 'Maintenance update',
            'subject' => 'Maintenance update for {hotel}: we are on it',
            'body' => "Hello,\n\nWe have run into a problem during the maintenance on your system. We are working on it now and will let you know as soon as it is fixed.\n\n"
                . "If you notice anything not working, please report it to us at {contact}, with what you see and when it happened.",
        ],
    ];
}

/** @return array<string,string> placeholder => what it is filled with (for the page) */
function maint_mail_placeholders(): array
{
    return [
        '{hotel}' => 'the business name',
        '{date}' => 'the day the window starts',
        '{from}' => 'the start time',
        '{to}' => 'the end time (with the date when it ends on another day)',
        '{zone}' => 'the time zone of these times',
        '{what goes offline}' => 'the part of the system that goes offline',
        '{message}' => 'the window\'s message (or the generic message)',
        '{contact}' => 'your email and phone (from Settings)',
    ];
}

/** The draft to start with for a window in this state. Pure. */
function maint_mail_default_draft(string $state): string
{
    return ['upcoming' => 'advance', 'announced' => 'advance', 'offline' => 'starting', 'ended' => 'back_online'][$state] ?? 'changed';
}

/** '' when the administrators may be emailed about this window, else why not. Pure. */
function maint_mail_can_email(array $r, int $now): string
{
    $s = maint_state($r, $now);
    if ($s === 'cancelled') {
        return 'This window was cancelled.';
    }
    if ($s === 'ended' && (int)$r['ends_at'] < $now - MAINT_MAIL_RECENT_DAYS * 86400) {
        return 'This window ended more than ' . MAINT_MAIL_RECENT_DAYS . ' days ago.';
    }
    return '';
}

/** The tag that links a cp_email_log row to its window and draft. Pure. */
function maint_mail_tag(int $windowId, string $draft): string
{
    return 'maintenance #' . $windowId . ' ' . $draft;
}

/** The window id in a tag, or 0. Pure. */
function maint_mail_tag_id(string $tag): int
{
    return preg_match('/^maintenance #(\d+) [a-z_]+$/D', $tag, $m) ? (int)$m[1] : 0;
}

/** "{contact}": the first company email and the phone. Pure. $company as em_company() returns it. */
function maint_mail_contact(array $company): string
{
    $emails = array_values(array_filter(array_map('trim', (array)($company['emails'] ?? [])), 'strlen'));
    $parts = array_filter([$emails[0] ?? '', trim((string)($company['phone'] ?? ''))], 'strlen');
    return $parts ? implode(' or ', $parts) : 'the usual address';
}

/**
 * The values of every placeholder for one window. Pure.
 * $r: cp_maintenance row; $message: the window's message or the generic message; $tz: the panel's time zone.
 */
function maint_mail_vars(array $r, string $hotel, string $message, array $company, string $tz): array
{
    $zone = new DateTimeZone($tz);
    $s = (new DateTimeImmutable('@' . (int)$r['starts_at']))->setTimezone($zone);
    $e = (new DateTimeImmutable('@' . (int)$r['ends_at']))->setTimezone($zone);
    $abbr = $s->format('T');
    $what = [
        'admin' => 'the staff system (admin, front desk, POS and kitchen screens)',
        'site' => 'the guest website',
        'both' => 'the staff system (admin, front desk, POS and kitchen screens) and the guest website',
    ];
    return [
        '{hotel}' => $hotel,
        '{date}' => $s->format('l j F Y'),
        '{from}' => $s->format('H:i'),
        '{to}' => $e->format('Y-m-d') === $s->format('Y-m-d') ? $e->format('H:i') : $e->format('l j F Y, H:i'),
        '{zone}' => preg_match('/^[A-Za-z]+$/D', $abbr) ? $abbr : 'UTC' . $s->format('P'),
        '{what goes offline}' => $what[(string)($r['scope'] ?? '')] ?? 'part of the system',
        '{message}' => $message,
        '{contact}' => maint_mail_contact($company),
    ];
}

/** Fills the placeholders in ONE pass (a value is never filled again). Plain text in, plain text out. Pure. */
function maint_mail_fill(string $tpl, array $vars): string
{
    return strtr($tpl, $vars);
}

/** A subject on one line, trimmed. Pure. */
function maint_mail_subject_line(string $s): string
{
    return trim((string)preg_replace('/\s+/u', ' ', str_replace(["\r", "\n", "\t"], ' ', $s)));
}

/** Checks a subject + message (a saved draft or one about to be sent): '' or the error. Pure. */
function maint_mail_check(string $subject, string $body): string
{
    if ($subject === '' || $body === '') {
        return 'Write a subject and a message.';
    }
    if (mb_strlen($subject) > MAINT_MAIL_SUBJECT_MAX) {
        return 'The subject can be at most ' . MAINT_MAIL_SUBJECT_MAX . ' characters.';
    }
    if (mb_strlen($body) > MAINT_MAIL_BODY_MAX) {
        return 'The message can be at most ' . MAINT_MAIL_BODY_MAX . ' characters.';
    }
    if ($subject !== strip_tags($subject) || $body !== strip_tags($body)) {
        return 'Write the subject and message as plain text (no HTML).';
    }
    return '';
}

/** Paragraphs of a plain text (blank lines between), empty ones dropped. Pure. */
function maint_mail_paras(string $text): array
{
    $out = [];
    foreach (preg_split('/\n\s*\n/', str_replace(["\r\n", "\r"], "\n", trim($text))) ?: [] as $p) {
        if (trim($p) !== '') {
            $out[] = trim($p);
        }
    }
    return $out;
}

/**
 * The email for one person: ['subject','html','text']. $subject / $body are already filled plain text. Pure (needs
 * app/email_templates.php). $company as em_company() returns it.
 */
function maint_mail_build(string $title, string $subject, string $body, array $company): array
{
    $c = $company;
    $c['thank_you'] = 'Thank you.';
    $paras = maint_mail_paras($body);
    $html = '';
    foreach ($paras as $p) {
        $html .= em_p(em_nl2br($p));
    }
    $text = em_text($title, (string)($paras[0] ?? ''), '', array_slice($paras, 1), [], $c, false);
    return ['subject' => maint_mail_subject_line($subject), 'html' => em_layout($title, maint_mail_subject_line($subject), $html, $c, false), 'text' => $text];
}

/**
 * Checks the text as the owner typed it (placeholders still in: plain text, length), fills it in ONE pass and builds the
 * email. Filled values (a business name with "<" in it, a long message) are never checked as typed text: they are escaped
 * in the HTML. @return array{0:string,1:?array} [error, email]. Pure (needs app/email_templates.php).
 */
function maint_mail_render(string $title, string $subject, string $body, array $vars, array $company): array
{
    $subject = maint_mail_subject_line($subject);
    $body = maint_clean_message($body);
    $err = maint_mail_check($subject, $body);
    if ($err !== '') {
        return [$err, null];
    }
    $fs = mb_substr(maint_mail_subject_line(maint_mail_fill($subject, $vars)), 0, 255);
    $fb = maint_mail_fill($body, $vars);
    if (mb_strlen($fb) > MAINT_MAIL_FINAL_MAX) {
        return ['The email is too long once the window\'s message is filled in (at most ' . MAINT_MAIL_FINAL_MAX . ' characters): shorten it.', null];
    }
    return ['', maint_mail_build($title, $fs, $fb, $company)];
}

/**
 * Who is ticked when the compose screen opens: administrators that have not yet had THIS draft about this window
 * successfully ($sent: lower-case email => when, from cp_email_log status sent). A failed or never-sent admin is ticked.
 * $posted (the ids ticked on a send that was refused, e.g. a double click): those ids instead of the administrators, still
 * minus anyone already sent, so "Send it again" never repeats to people it reached.
 * @return array<int,bool> pms_user_id => ticked. Pure.
 */
function maint_mail_default_ticks(array $candidates, array $sent, ?array $posted = null): array
{
    $want = $posted === null ? null : array_flip(array_map('intval', $posted));
    $out = [];
    foreach ($candidates as $c) {
        $pick = $want === null ? $c['is_admin'] : isset($want[$c['pms_user_id']]);
        $out[$c['pms_user_id']] = $pick && !isset($sent[strtolower($c['email'])]);
    }
    return $out;
}

/** True when this person is an administrator of the business: its super admin or a role with every permission. Pure. */
function maint_mail_is_admin(?array $catalog, array $s): bool
{
    return hms_is_super_admin($s) || ($catalog !== null && hms_role_is_all($catalog, (string)($s['role'] ?? '')));
}

/**
 * Who can be emailed: ACTIVE staff with a valid email, one row per address (case-insensitive), administrators first.
 * Rows: pms_user_id, name, role (label), email, is_admin. Pure.
 */
function maint_mail_candidates(array $staff, ?array $catalog): array
{
    $rows = [];
    foreach ($staff as $s) {
        $email = trim((string)($s['email'] ?? ''));
        if ((int)($s['is_active'] ?? 0) !== 1 || $email === '' || preg_match('/[\r\n]/', $email)
            || !filter_var($email, FILTER_VALIDATE_EMAIL) || (int)($s['pms_user_id'] ?? 0) <= 0) {
            continue;
        }
        $role = (string)($s['role'] ?? '');
        $rows[] = [
            'pms_user_id' => (int)$s['pms_user_id'],
            'name' => trim((string)($s['full_name'] ?? '')) !== '' ? trim((string)$s['full_name']) : (string)($s['username'] ?? ''),
            'role' => hms_is_super_admin($s) ? 'Super admin' : (string)($catalog['roles'][$role]['label'] ?? $role),
            'email' => $email,
            'is_admin' => maint_mail_is_admin($catalog, $s),
        ];
    }
    usort($rows, static fn($a, $b) => [$b['is_admin'], mb_strtolower($a['name']), $a['pms_user_id']] <=> [$a['is_admin'], mb_strtolower($b['name']), $b['pms_user_id']]);
    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        $k = strtolower($r['email']);
        if (!isset($seen[$k])) {
            $seen[$k] = true;
            $out[] = $r;
        }
    }
    return $out;
}

/**
 * The people to send to. $ids null = the administrators; else the ticked pms_user_ids (strings or ints), each of which must
 * be in $candidates. @return array{0:string,1:array} [error, rows]. Pure.
 */
function maint_mail_choose(array $candidates, ?array $ids, int $cap = MAINT_MAIL_CAP): array
{
    if ($ids === null) {
        $out = array_values(array_filter($candidates, static fn($c) => $c['is_admin']));
    } else {
        $want = [];
        foreach ($ids as $v) {
            if ((is_int($v) && $v > 0) || (is_string($v) && ctype_digit($v) && (int)$v > 0)) {
                $want[(int)$v] = true;
            }
        }
        $out = array_values(array_filter($candidates, static fn($c) => isset($want[$c['pms_user_id']])));
        if ($want && !$out) {
            return ['The people ticked are no longer on this business\'s staff list. Reload the page.', []];
        }
        if (!$want) {
            return ['Tick at least one person.', []];
        }
    }
    if (count($out) > $cap) {
        return ['At most ' . $cap . ' people per send: untick some and send the rest after.', []];
    }
    return ['', $out];
}

/** Why nobody can be emailed at this business (plain words). Pure. */
function maint_mail_none_reason(string $hotel, array $staff): string
{
    if (!$staff) {
        return 'No administrator email known for ' . $hotel . ': its system has not sent its staff list yet. Ask them to open their Licence page, or add an email to their admin account.';
    }
    return 'No administrator email known for ' . $hotel . ': ask them to open their Licence page, or add an email to their admin account.';
}

// ── Database ───────────────────────────────────────────────────────────────

/** One draft: the saved one, else the built-in one. ['subject','body','custom'=>bool] plus label and title. */
function maint_mail_draft(PDO $pdo, string $key): array
{
    $d = maint_mail_drafts()[$key] ?? maint_mail_drafts()['advance'];
    $d['custom'] = false;
    try {
        $st = $pdo->prepare('SELECT setting_value FROM cp_settings WHERE setting_key = ?');
        $st->execute([MAINT_MAIL_SETTING . $key]);
        $v = json_decode((string)$st->fetchColumn(), true);
        if (is_array($v) && is_string($v['subject'] ?? null) && is_string($v['body'] ?? null) && $v['subject'] !== '' && $v['body'] !== '') {
            $d['subject'] = $v['subject'];
            $d['body'] = $v['body'];
            $d['custom'] = true;
        }
    } catch (Throwable $e) {
        error_log('maint_mail_draft: ' . get_class($e));
    }
    return $d;
}

/** Saves a draft; null subject = revert to the built-in one. Returns '' or the error. Audited. */
function maint_mail_save_draft(PDO $pdo, string $key, ?string $subject, ?string $body, int $adminId): string
{
    $builtin = maint_mail_drafts()[$key] ?? null;
    if ($builtin === null) {
        return 'Unknown draft.';
    }
    $before = maint_mail_draft($pdo, $key);
    $revert = $subject === null;
    if (!$revert) {
        $subject = maint_mail_subject_line($subject);
        $body = maint_clean_message((string)$body);
        $err = maint_mail_check($subject, $body);
        if ($err !== '') {
            return $err;
        }
        $revert = $subject === $builtin['subject'] && $body === $builtin['body'];
    }
    if ($revert) {
        $pdo->prepare('DELETE FROM cp_settings WHERE setting_key = ?')->execute([MAINT_MAIL_SETTING . $key]);
    } else {
        $pdo->prepare('INSERT INTO cp_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([MAINT_MAIL_SETTING . $key, json_encode(['subject' => $subject, 'body' => $body], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
    if (function_exists('billing_settings_cache')) {
        billing_settings_cache(null, true);
    }
    audit_log($pdo, $adminId ?: null, $revert ? 'maintenance_email_draft_reverted' : 'maintenance_email_draft_saved', null,
        ['draft' => $key, 'subject' => $before['subject'], 'body' => $before['body']],
        ['draft' => $key, 'subject' => $revert ? $builtin['subject'] : $subject, 'body' => $revert ? $builtin['body'] : $body]);
    return '';
}

/** The company block for these emails (Settings, with the built-in defaults for anything missing). */
function maint_mail_company(): array
{
    require_once __DIR__ . '/email_templates.php';
    return em_company(function_exists('billing_company') ? billing_company() : []);
}

/** Everything the compose screen and the send need about one window: [error, window, tenant, staff, candidates]. */
function maint_mail_context(PDO $pdo, int $windowId, int $now): array
{
    $r = maint_find($pdo, $windowId);
    if ($r === null) {
        return ['That window no longer exists.', null, null, [], []];
    }
    $why = maint_mail_can_email($r, $now);
    if ($why !== '') {
        return [$why, $r, null, [], []];
    }
    $t = tenant_find($pdo, (int)$r['tenant_id']);
    if ($t === null) {
        return ['That business no longer exists.', $r, null, [], []];
    }
    $staff = hms_staff($pdo, (int)$t['id']);
    return ['', $r, $t, $staff, maint_mail_candidates($staff, hms_catalog($t))];
}

/** The placeholder values for one window (its own message, else the generic message). */
function maint_mail_window_vars(PDO $pdo, array $r, array $t, array $company, string $tz): array
{
    $msg = $r['message'] ?? null;
    return maint_mail_vars($r, (string)$t['name'], (is_string($msg) && trim($msg) !== '') ? $msg : maint_default_message($pdo), $company, $tz);
}

/** Who already got this draft about this window: lower-case email => last successful send (status sent only). */
function maint_mail_sent_to(PDO $pdo, int $windowId, string $draft): array
{
    $st = $pdo->prepare("SELECT to_email, MAX(created_at) AS last FROM cp_email_log WHERE kind = 'maintenance' AND attachment = ? AND status = 'sent' GROUP BY to_email");
    $st->execute([maint_mail_tag($windowId, $draft)]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[strtolower((string)$row['to_email'])] = (string)$row['last'];
    }
    return $out;
}

/** Sends of this window per draft key in the last $seconds (PHP clock, as the log rows are written). */
function maint_mail_recent(PDO $pdo, int $windowId, string $draft, int $now, int $seconds): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM cp_email_log WHERE kind = 'maintenance' AND attachment = ? AND created_at >= ? AND status IN ('sent','test')");
    $st->execute([maint_mail_tag($windowId, $draft), date('Y-m-d H:i:s', $now - $seconds)]);
    return (int)$st->fetchColumn();
}

/** "Emailed" per window id: [id => ['last' => 'Y-m-d H:i:s', 'n' => count]] (sent or test only). */
function maint_mail_sent_summary(PDO $pdo, array $tenantIds): array
{
    $tenantIds = array_values(array_unique(array_filter(array_map('intval', $tenantIds), static fn($i) => $i > 0)));
    if (!$tenantIds) {
        return [];
    }
    $st = $pdo->prepare("SELECT attachment, MAX(created_at) AS last, COUNT(*) AS n FROM cp_email_log WHERE kind = 'maintenance' AND status IN ('sent','test')
        AND tenant_id IN (" . implode(',', array_fill(0, count($tenantIds), '?')) . ') GROUP BY attachment');
    $st->execute($tenantIds);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $id = maint_mail_tag_id((string)$row['attachment']);
        if ($id <= 0) {
            continue;
        }
        $cur = $out[$id] ?? ['last' => '', 'n' => 0];
        $out[$id] = ['last' => max($cur['last'], (string)$row['last']), 'n' => $cur['n'] + (int)$row['n']];
    }
    return $out;
}

/**
 * Sends one draft about one window. $ids null = the business's administrators; else the ticked pms_user_ids (only that
 * business's active staff with an email can be chosen). $subject / $body: the (edited) text; placeholders in it are filled.
 * $again: send even when the same draft went out for this window in the last minute.
 * @return array{error:string,sent:int,failed:int,reasons:string[],test:bool,hotel:string}
 */
function maint_mail_send(PDO $pdo, int $windowId, string $draft, string $subject, string $body, ?array $ids, int $adminId, int $now, string $tz, bool $again): array
{
    $res = ['error' => '', 'sent' => 0, 'failed' => 0, 'reasons' => [], 'test' => false, 'hotel' => ''];
    if (!isset(maint_mail_drafts()[$draft])) {
        $res['error'] = 'Choose a draft.';
        return $res;
    }
    [$err, $r, $t, $staff, $cands] = maint_mail_context($pdo, $windowId, $now);
    if ($err !== '') {
        $res['error'] = $err;
        return $res;
    }
    $hotel = (string)$t['name'];
    $res['hotel'] = $hotel;
    $err = maint_mail_check(maint_mail_subject_line($subject), maint_clean_message($body)); // the typed text, placeholders still in
    if ($err !== '') {
        $res['error'] = $err;
        return $res;
    }
    if (!$cands || ($ids === null && !array_filter($cands, static fn($c) => $c['is_admin']))) {
        $res['error'] = maint_mail_none_reason($hotel, $staff);
        return $res;
    }
    [$err, $people] = maint_mail_choose($cands, $ids);
    if ($err !== '') {
        $res['error'] = $err;
        return $res;
    }
    // One send per window at a time: a second click while the first is still sending is refused straight away.
    $lock = 'cp_maintmail_' . $windowId;
    $st = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $st->execute([$lock]);
    if ((int)$st->fetchColumn() !== 1) {
        $res['error'] = 'An email about this window is being sent right now. Wait a moment and check the list.';
        return $res;
    }
    try {
        if (!$again && maint_mail_recent($pdo, $windowId, $draft, $now, MAINT_MAIL_REPEAT) > 0) {
            $res['error'] = 'This draft was sent about this window less than a minute ago. Tick "Send it again" to send it once more.';
            return $res;
        }
        require_once __DIR__ . '/mailer.php';
        $company = maint_mail_company();
        [$err, $mail] = maint_mail_render(maint_mail_drafts()[$draft]['title'], $subject, $body, maint_mail_window_vars($pdo, $r, $t, $company, $tz), $company);
        if ($err !== '') {
            $res['error'] = $err;
            return $res;
        }
        $log = $pdo->prepare('INSERT INTO cp_email_log (tenant_id, kind, to_email, subject, status, error, attachment, admin_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $sentTo = [];
        @set_time_limit(30 + 25 * count($people));
        foreach ($people as $p) {
            try {
                $m = mail_send(['to' => [$p['email']], 'subject' => $mail['subject'], 'html' => $mail['html'], 'text' => $mail['text']]);
            } catch (Throwable $e) {
                error_log('maint_mail: send to one person failed: ' . get_class($e) . ': ' . $e->getMessage());
                $m = ['ok' => false, 'error' => 'an error on this server stopped this email (logged)'];
            }
            $ok = !empty($m['ok']);
            $res['test'] = $res['test'] || !empty($m['test']);
            if ($ok) {
                $res['sent']++;
                $sentTo[] = $p['email'];
            } else {
                $res['failed']++;
                $res['reasons'][] = $p['email'] . ': ' . (string)($m['error'] ?? 'not sent');
            }
            try {
                $log->execute([(int)$t['id'], 'maintenance', mb_substr($p['email'], 0, 500, 'UTF-8'), mb_substr($mail['subject'], 0, 255, 'UTF-8'),
                    $ok ? (!empty($m['test']) ? 'test' : 'sent') : 'failed', $ok ? null : mb_substr((string)($m['error'] ?? ''), 0, 500, 'UTF-8'),
                    maint_mail_tag($windowId, $draft), $adminId > 0 ? $adminId : null, date('Y-m-d H:i:s')]);
            } catch (Throwable $e) {
                error_log('maint_mail: email log: ' . get_class($e));
            }
        }
        try {
            audit_log($pdo, $adminId ?: null, 'maintenance_emailed', (int)$t['id'], null, [
                'id' => $windowId, 'business' => $hotel, 'draft' => $draft, 'subject' => $mail['subject'],
                'sent' => $res['sent'], 'failed' => $res['failed'], 'to' => $sentTo, 'test_mode' => $res['test'],
            ]);
        } catch (Throwable $e) {
            error_log('maint_mail: audit: ' . get_class($e));
        }
        return $res;
    } finally {
        try {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        } catch (Throwable $e) {
            error_log('maint_mail: release lock: ' . get_class($e)); // the lock ends with the connection anyway
        }
    }
}

/** "Sent to N, failed M (reasons)" for the flash. Pure. */
function maint_mail_result_words(array $res): string
{
    $w = 'Sent to ' . $res['sent'] . ', failed ' . $res['failed'];
    if ($res['reasons']) {
        $w .= ' (' . implode('; ', array_slice($res['reasons'], 0, 5)) . (count($res['reasons']) > 5 ? '; ...' : '') . ')';
    }
    return $w . '.' . (!empty($res['test']) ? ' Test mode is on (MAIL_TEST_TO): every email went to the test address only.' : '');
}
