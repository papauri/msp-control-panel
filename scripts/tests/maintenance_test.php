<?php
// Maintenance windows (app/maintenance.php, 2026-11-23).
// Pure tests always run: validation, the notice lead, time parsing, overlap, which window is sent, End now / Cancel rules.
// With --db a smoke test runs on the real database inside ONE transaction that is rolled back (cp_ tables only). When
// migration 014 is not applied yet, cp_maintenance is created as a TEMPORARY table for this connection only (it never
// commits and disappears when the test ends).
//   php scripts/tests/maintenance_test.php        pure tests
//   php scripts/tests/maintenance_test.php --db   pure tests + rolled-back database smoke test
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/maintenance.php';

$now = 1_800_000_000; // a fixed clock
$H = 3600;
$ok = static fn(array $over = []): array => array_merge(
    ['scope' => 'admin', 'announce_from' => $now + 2 * $H, 'starts_at' => $now + 26 * $H, 'ends_at' => $now + 28 * $H, 'message' => ''], $over);

// --- validation
[$err, $c] = maint_validate($ok(), $now);
t_eq('a good window passes', [], $err);
t_eq('an empty message means the generic one (null)', null, $c['message']);
[$err, $c] = maint_validate($ok(['message' => "  Patch night.\r\nBack soon.\x07 "]), $now);
t_eq('message cleaned: line ends, control characters, spaces', "Patch night.\nBack soon.", $c['message']);
t_ok('unknown scope refused', maint_validate($ok(['scope' => 'everything']), $now)[0] !== []);
foreach (['admin', 'site', 'both'] as $sc) {
    t_eq("scope $sc accepted", [], maint_validate($ok(['scope' => $sc]), $now)[0]);
}
t_ok('end before start refused', maint_validate($ok(['ends_at' => $now + 25 * $H]), $now)[0] !== []);
t_ok('end equal to start refused', maint_validate($ok(['ends_at' => $now + 26 * $H]), $now)[0] !== []);
t_eq('exactly 72 hours allowed', [], maint_validate($ok(['ends_at' => $now + 26 * $H + MAINT_MAX_WINDOW]), $now)[0]);
t_ok('72 hours and a second refused', maint_validate($ok(['ends_at' => $now + 26 * $H + MAINT_MAX_WINDOW + 1]), $now)[0] !== []);
t_ok('notice after the start refused', maint_validate($ok(['announce_from' => $now + 27 * $H]), $now)[0] !== []);
t_eq('notice exactly at the start allowed', [], maint_validate($ok(['announce_from' => $now + 26 * $H]), $now)[0]);
t_eq('notice exactly 30 days before allowed', [], maint_validate($ok(['announce_from' => $now + 26 * $H - MAINT_MAX_ANNOUNCE]), $now)[0]);
t_ok('notice more than 30 days before refused', maint_validate($ok(['announce_from' => $now + 26 * $H - MAINT_MAX_ANNOUNCE - 1]), $now)[0] !== []);
t_ok('start in the past refused', maint_validate($ok(['starts_at' => $now - 3600, 'announce_from' => $now - 7200]), $now)[0] !== []);
t_eq('start a few minutes ago allowed (means now)', [], maint_validate($ok(['starts_at' => $now - 300, 'announce_from' => $now - 300]), $now)[0]);
t_ok('missing times refused', maint_validate(['scope' => 'admin'], $now)[0] !== []);
t_ok('times as strings refused', maint_validate($ok(['starts_at' => (string)($now + 26 * $H)]), $now)[0] !== []);
t_eq('1000 characters allowed', [], maint_validate($ok(['message' => str_repeat('é', 1000)]), $now)[0]);
t_ok('1001 characters refused', maint_validate($ok(['message' => str_repeat('a', 1001)]), $now)[0] !== []);
t_ok('HTML refused', maint_validate($ok(['message' => 'Back <b>soon</b>']), $now)[0] !== []);

// --- notice lead and times
$st = $now + 26 * $H;
t_eq('notice 1 day before', $st - 86400, maint_announce_from($st, '1d', $now));
t_eq('notice "as soon as saved" is now', $now, maint_announce_from($st, 'now', $now));
t_eq('unknown lead falls back to 1 day', $st - 86400, maint_announce_from($st, 'zz', $now));
t_eq('notice never after the start', $now - 60, maint_announce_from($now - 60, 'now', $now));
// Offline now for N minutes/hours (owner 2026-11-24)
t_eq('now: 30 minutes', [$now, $now + 1800], maint_now_window('30', 'minutes', $now));
t_eq('now: 2 hours', [$now, $now + 7200], maint_now_window('2', 'hours', $now));
t_eq('now: spaces trimmed', [$now, $now + 300], maint_now_window(' 5 ', 'minutes', $now));
t_eq('now: zero refused', null, maint_now_window('0', 'minutes', $now));
t_eq('now: negative refused', null, maint_now_window('-5', 'minutes', $now));
t_eq('now: decimal refused', null, maint_now_window('1.5', 'hours', $now));
t_eq('now: empty refused', null, maint_now_window('', 'hours', $now));
t_eq('now: unknown unit refused', null, maint_now_window('5', 'days', $now));
t_eq('now: leading zero refused', null, maint_now_window('05', 'minutes', $now));
$w = maint_now_window('72', 'hours', $now);
t_eq('now: 72 hours passes validation', [], maint_validate($ok(['starts_at' => $w[0], 'ends_at' => $w[1], 'announce_from' => maint_announce_from($w[0], 'now', $now)]), $now)[0]);
$w = maint_now_window('73', 'hours', $now);
t_ok('now: 73 hours refused by the 72-hour cap', maint_validate($ok(['starts_at' => $w[0], 'ends_at' => $w[1], 'announce_from' => $now]), $now)[0] !== []);
$w = maint_now_window('4321', 'minutes', $now);
t_ok('now: 4321 minutes refused by the cap', maint_validate($ok(['starts_at' => $w[0], 'ends_at' => $w[1], 'announce_from' => $now]), $now)[0] !== []);
t_eq('30 days lead passes validation', [], maint_validate($ok(['announce_from' => maint_announce_from($st, '30d', $now)]), $now)[0]);
t_eq('local time parsed in its zone', (new DateTimeImmutable('2026-11-25 22:00', new DateTimeZone('Africa/Blantyre')))->getTimestamp(),
    maint_parse_local('2026-11-25T22:00', 'Africa/Blantyre'));
t_eq('bad date refused', null, maint_parse_local('2026-02-30T22:00', 'UTC'));
t_eq('bad format refused', null, maint_parse_local('25/11/2026 22:00', 'UTC'));
t_eq('bad zone refused', null, maint_parse_local('2026-11-25T22:00', 'Nowhere/Land'));

// --- overlap
$rows = [
    ['id' => 1, 'state' => 'planned', 'starts_at' => 100, 'ends_at' => 200],
    ['id' => 2, 'state' => 'cancelled', 'starts_at' => 300, 'ends_at' => 400],
    ['id' => 3, 'state' => 'ended', 'starts_at' => 500, 'ends_at' => 600],
];
t_ok('overlaps a planned window', maint_overlaps($rows, 150, 250));
t_ok('inside a planned window', maint_overlaps($rows, 120, 130));
t_ok('around a planned window', maint_overlaps($rows, 50, 250));
t_ok('touching at the end does not overlap', !maint_overlaps($rows, 200, 250));
t_ok('touching at the start does not overlap', !maint_overlaps($rows, 50, 100));
t_ok('a cancelled window never blocks', !maint_overlaps($rows, 300, 400));
t_ok('an ended window never blocks', !maint_overlaps($rows, 500, 600));
t_ok('its own row is skipped', !maint_overlaps($rows, 150, 250, 1));

// --- state on the page
$w = ['id' => 9, 'state' => 'planned', 'announce_from' => 100, 'starts_at' => 200, 'ends_at' => 300];
t_eq('before the notice: upcoming', 'upcoming', maint_state($w, 99));
t_eq('from the notice: announced', 'announced', maint_state($w, 100));
t_eq('at the start: offline', 'offline', maint_state($w, 200));
t_eq('one second before the end: offline', 'offline', maint_state($w, 299));
t_eq('at the end: ended', 'ended', maint_state($w, 300));
t_eq('ended by hand', 'ended', maint_state(['state' => 'ended'] + $w, 250));
t_eq('cancelled', 'cancelled', maint_state(['state' => 'cancelled'] + $w, 250));

// --- the block sent
t_eq('nothing planned: null', null, maint_pick([], $now));
$pick = maint_pick([
    ['id' => 4, 'state' => 'planned', 'starts_at' => 500, 'ends_at' => 600],
    ['id' => 5, 'state' => 'planned', 'starts_at' => 200, 'ends_at' => 300],
    ['id' => 6, 'state' => 'cancelled', 'starts_at' => 100, 'ends_at' => 900],
    ['id' => 7, 'state' => 'planned', 'starts_at' => 50, 'ends_at' => 150],
], 150);
t_eq('the planned window not over that starts first (an over one and a cancelled one skipped)', 5, (int)$pick['id']);
t_eq('tie on start: lowest id', 2, (int)maint_pick([['id' => 3, 'state' => 'planned', 'starts_at' => 10, 'ends_at' => 20], ['id' => 2, 'state' => 'planned', 'starts_at' => 10, 'ends_at' => 30]], 0)['id']);
t_eq('a window ending now is not sent', null, maint_pick([['id' => 1, 'state' => 'planned', 'starts_at' => 10, 'ends_at' => 20]], 20));
$b = maint_block(['id' => '5', 'scope' => 'both', 'announce_from' => '100', 'starts_at' => '200', 'ends_at' => '300', 'message' => null], 'Generic words');
t_eq('block: ints, the generic message filled in', ['id' => 5, 'scope' => 'both', 'announce_from' => 100, 'starts_at' => 200, 'ends_at' => 300, 'message' => 'Generic words'], $b);
t_eq('block: its own message wins', 'Own words', maint_block(['message' => 'Own words'] + ['id' => 1, 'scope' => 'site', 'announce_from' => 1, 'starts_at' => 2, 'ends_at' => 3], 'G')['message']);
t_ok('built-in generic message is plain text and short enough', maint_builtin_message() === strip_tags(maint_builtin_message()) && mb_strlen(maint_builtin_message()) <= MAINT_MESSAGE_MAX);

// --- End now / Cancel
t_eq('End now: only while offline', '', maint_can_end($w, 250));
t_ok('End now refused before the start', maint_can_end($w, 150) !== '');
t_ok('End now refused after the end', maint_can_end($w, 300) !== '');
t_eq('Cancel: before the start', '', maint_can_cancel($w, 150));
t_eq('Cancel: while only announced', '', maint_can_cancel($w, 100));
t_ok('Cancel refused while running', maint_can_cancel($w, 250) !== '');
t_ok('Cancel refused once cancelled', maint_can_cancel(['state' => 'cancelled'] + $w, 150) !== '');

// --- the page and the endpoint (source checks)
$root = dirname(__DIR__, 2);
$pageSrc = (string)file_get_contents($root . '/public/maintenance.php');
$apiSrc = (string)file_get_contents($root . '/public/api/licence.php');
t_ok('page: signed-in only', strpos($pageSrc, 'auth_require($pdo)') !== false);
t_ok('page: CSRF on every POST', strpos($pageSrc, "csrf_require();") !== false && substr_count($pageSrc, 'csrf_field()') >= 4);
t_ok('page: no inline script (the CSP forbids it)', stripos($pageSrc, 'onsubmit=') === false && stripos($pageSrc, 'onclick=') === false);
t_ok('page: says the staff system covers front desk, POS and kitchen', strpos($pageSrc, 'front desk, POS, kitchen') !== false);
t_ok('endpoint: maintenance inside its own try/catch', (bool)preg_match('/try \{\s*\$seen = .*?maint_answer_block.*?\} catch \(Throwable \$e\)/s', $apiSrc));
t_ok('nav: Maintenance link', strpos((string)file_get_contents($root . '/app/view.php'), "['maintenance.php', 'Maintenance']") !== false);
t_ok('migration 014: no ";" inside a statement (migrate.php splits on it)', (static function (string $sql): bool {
    $sql = (string)preg_replace('/^\s*--.*$/m', '', $sql);
    return count(array_filter(array_map('trim', explode(';', $sql)))) === 1;
})((string)file_get_contents($root . '/migrations/014_maintenance.sql')));

// --- emailing the administrators (app/maint_mail.php, owner 2026-11-24): pure parts only, nothing is sent
require_once __DIR__ . '/../../app/hms.php';
require_once __DIR__ . '/../../app/email_templates.php';
require_once __DIR__ . '/../../app/maint_mail.php';
t_eq('mail: six drafts in page order', ['advance', 'reminder', 'starting', 'changed', 'back_online', 'problem'], array_keys(maint_mail_drafts()));
foreach (maint_mail_drafts() as $k => $d) {
    t_eq("mail: built-in draft $k passes its own check", '', maint_mail_check($d['subject'], $d['body']));
    t_ok("mail: built-in draft $k uses no unknown placeholder", (static function (string $s): bool {
        preg_match_all('/\{[^}]*\}/', $s, $m);
        return !array_diff($m[0], array_keys(maint_mail_placeholders()));
    })($d['subject'] . $d['body']));
    t_ok("mail: built-in draft $k has no AI words", !preg_match('/\b(AI|smart|insight|powered by)\b/i', $d['subject'] . $d['body']));
}
foreach (['upcoming' => 'advance', 'announced' => 'advance', 'offline' => 'starting', 'ended' => 'back_online', 'cancelled' => 'changed', 'junk' => 'changed'] as $st => $want) {
    t_eq("mail: default draft for $st", $want, maint_mail_default_draft($st));
}
// placeholders, filled in Africa/Blantyre (UTC+2): 2026-11-25 12:00 UTC = 14:00 there
$co = em_company(['emails' => ['help@example.com', 'sales@example.com'], 'phone' => '+265 1 234']);
$win = ['id' => 7, 'scope' => 'both', 'announce_from' => 1795000000, 'starts_at' => 1795608000, 'ends_at' => 1795608000 + 2 * $H, 'state' => 'planned', 'message' => null];
$v = maint_mail_vars($win, 'Lake Hotel', 'Generic words.', $co, 'Africa/Blantyre');
t_eq('mail: {date} in the panel time zone', 'Wednesday 25 November 2026', $v['{date}']);
t_eq('mail: {from}', '14:00', $v['{from}']);
t_eq('mail: {to} on the same day is the time only', '16:00', $v['{to}']);
t_eq('mail: {zone}', 'CAT', $v['{zone}']);
t_eq('mail: {contact} = first email + phone', 'help@example.com or +265 1 234', $v['{contact}']);
t_ok('mail: {what goes offline} for both', strpos($v['{what goes offline}'], 'guest website') !== false && strpos($v['{what goes offline}'], 'staff system') !== false);
$v2 =maint_mail_vars(array_merge($win, ['starts_at' => 1795608000 + 9 * $H, 'ends_at' => 1795608000 + 13 * $H]), 'X', 'm', $co, 'Africa/Blantyre');
t_eq('mail: a window across midnight: {from} 23:00', '23:00', $v2['{from}']);
t_eq('mail: a window across midnight: {to} carries the date', 'Thursday 26 November 2026, 03:00', $v2['{to}']);
t_eq('mail: a zone without letters reads as UTC+hh:mm', 'UTC+03:00', maint_mail_vars($win, 'X', 'm', $co, 'Etc/GMT-3')['{zone}']);
t_eq('mail: no company email or phone', 'the usual address', maint_mail_contact(['emails' => [], 'phone' => '']));
t_eq('mail: fill', 'Lake Hotel on Wednesday 25 November 2026 at 14:00', maint_mail_fill('{hotel} on {date} at {from}', $v));
t_eq('mail: a placeholder inside a value is never filled again', '{message} x', maint_mail_fill('{hotel} x', array_merge($v, ['{hotel}' => '{message}'])));
t_eq('mail: unknown braces left alone', '{nope} Lake Hotel', maint_mail_fill('{nope} {hotel}', $v));
// hostile hotel name and message: escaped in the HTML, kept as typed in the plain text
$evil = maint_mail_vars($win, '<script>alert(1)</script> & "Inn"', "<img src=x onerror=alert(2)>\nLine two", $co, 'Africa/Blantyre');
$d = maint_mail_drafts()['advance'];
$mail = maint_mail_build($d['title'], maint_mail_fill($d['subject'], $evil), maint_mail_fill($d['body'], $evil), $co);
t_ok('mail: HTML has no raw <script> from the name', stripos($mail['html'], '<script>') === false && strpos($mail['html'], '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;Inn&quot;') !== false);
t_ok('mail: HTML has no raw <img> from the message', stripos($mail['html'], '<img src=x') === false && strpos($mail['html'], '&lt;img src=x onerror=alert(2)&gt;<br>Line two') !== false);
t_ok('mail: the plain text keeps the words as typed', strpos($mail['text'], '<img src=x onerror=alert(2)>') !== false && strpos($mail['text'], 'Hello,') !== false);
t_ok('mail: the subject is one line', strpos(maint_mail_build('T', "A\r\nBcc: x@y.z", 'b', $co)['subject'], "\n") === false);
t_ok('mail: the layout says a plain thank you (not "your business")', strpos($mail['html'], '<strong>Thank you.</strong>') !== false && strpos($mail['html'], 'Thank you for your business') === false);
t_eq('mail: HTML in a draft is refused', 'Write the subject and message as plain text (no HTML).', maint_mail_check('Hi', 'a <b>bold</b> move'));
t_ok('mail: an empty subject is refused', maint_mail_check('', 'body') !== '');
t_ok('mail: an over-long message is refused', maint_mail_check('s', str_repeat('a', MAINT_MAIL_BODY_MAX + 1)) !== '');
t_eq('mail: paragraphs split on blank lines, empty ones dropped', ['a', "b\nc"], maint_mail_paras("a\n\n\n  \nb\nc\n\n"));
// who is an administrator, and who can be emailed
$cat = ['roles' => ['admin' => ['label' => 'Administrator', 'level' => 100, 'defaults' => null], 'manager' => ['label' => 'Manager', 'level' => 50, 'defaults' => ['x']],
    'clerk' => ['label' => 'Front desk', 'level' => 10, 'defaults' => []]], 'permissions' => ['x' => ['label' => 'X', 'category' => 'C']]];
$staffRow = static fn(int $id, string $role, string $email, int $active = 1, int $super = 0, string $name = ''): array =>
    ['pms_user_id' => $id, 'username' => 'u' . $id, 'full_name' => $name !== '' ? $name : 'Person ' . $id, 'email' => $email, 'role' => $role, 'is_active' => $active, 'is_super_admin' => $super, 'denies' => [], 'grants' => []];
$staff = [
    $staffRow(1, 'clerk', 'desk@hotel.test', 1, 0, 'Ann Desk'),
    $staffRow(2, 'manager', 'boss@hotel.test', 1, 1, 'Ben Super'),      // super admin with a limited role
    $staffRow(3, 'admin', 'admin@hotel.test', 1, 0, 'Cat Admin'),       // all-permission role
    $staffRow(4, 'admin', 'gone@hotel.test', 0, 0, 'Dan Inactive'),     // inactive
    $staffRow(5, 'admin', '', 1, 0, 'Eve No Email'),                    // no email
    $staffRow(6, 'admin', "bad\r\nBcc: x@y.z", 1, 0, 'Fay Bad'),        // header injection attempt
    $staffRow(7, 'admin', 'not-an-email', 1, 0, 'Gus Junk'),
    $staffRow(8, 'clerk', 'ADMIN@hotel.test', 1, 0, 'Hal Same'),        // same address as Cat, another case
];
$cands = maint_mail_candidates($staff, $cat);
t_eq('mail: candidates = active staff with a valid email, one per address, admins first', [2, 3, 1], array_column($cands, 'pms_user_id'));
t_eq('mail: admin flags', [true, true, false], array_column($cands, 'is_admin'));
t_eq('mail: the super admin is labelled', 'Super admin', $cands[0]['role']);
[$e1, $admins] = maint_mail_choose($cands, null);
t_eq('mail: administrators by default (super admin + all-permission role)', ['', ['boss@hotel.test', 'admin@hotel.test']], [$e1, array_column($admins, 'email')]);
t_eq('mail: with no catalog only the super admin counts', [true, false, false], array_column(maint_mail_candidates($staff, null), 'is_admin'));
[$e1, $pick] = maint_mail_choose($cands, ['1', '4', '5', '999', 'x', '3']);
t_eq('mail: only ticked ids that are candidates (inactive / no email / unknown dropped)', ['', [3, 1]], [$e1, array_column($pick, 'pms_user_id')]);
t_ok('mail: nothing ticked is refused', maint_mail_choose($cands, [])[0] !== '');
t_ok('mail: only unknown ids ticked is refused', maint_mail_choose($cands, ['999'])[0] !== '');
$many = [];
for ($i = 1; $i <= 51; $i++) {
    $many[] = $staffRow($i, 'admin', "a$i@hotel.test");
}
$cm = maint_mail_candidates($many, $cat);
t_eq('mail: 50 people allowed', 50, count(maint_mail_choose($cm, array_map('strval', range(1, 50)))[1]));
t_ok('mail: 51 people refused, nothing chosen', maint_mail_choose($cm, array_map('strval', range(1, 51))) [0] !== '' && maint_mail_choose($cm, array_map('strval', range(1, 51)))[1] === []);
t_ok('mail: 51 administrators by default refused too', maint_mail_choose($cm, null)[0] !== '');
t_ok('mail: no staff list => plain words', strpos(maint_mail_none_reason('Lake Hotel', []), 'No administrator email known for Lake Hotel') === 0);
t_eq('mail: no admin with an email', 'No administrator email known for Lake Hotel: ask them to open their Licence page, or add an email to their admin account.', maint_mail_none_reason('Lake Hotel', $staff));
// which windows can be emailed about
t_eq('mail: upcoming window', '', maint_mail_can_email($win, 1795000000));
t_eq('mail: running window', '', maint_mail_can_email($win, 1795608000 + $H));
t_ok('mail: cancelled window refused', maint_mail_can_email(array_merge($win, ['state' => 'cancelled']), $now) !== '');
$endedRecent = array_merge($win, ['state' => 'ended', 'starts_at' => $now - 10 * 86400, 'ends_at' => $now - 7 * 86400]);
t_eq('mail: ended exactly 7 days ago still allowed', '', maint_mail_can_email($endedRecent, $now));
t_ok('mail: ended 7 days and a second ago refused', maint_mail_can_email(array_merge($endedRecent, ['ends_at' => $now - 7 * 86400 - 1]), $now) !== '');
t_eq('mail: tag round trip', 12, maint_mail_tag_id(maint_mail_tag(12, 'back_online')));
t_eq('mail: a foreign attachment name is not a window', 0, maint_mail_tag_id('invoice-12.pdf'));
t_eq('mail: result words', 'Sent to 2, failed 1 (c@d.test: refused).', maint_mail_result_words(['sent' => 2, 'failed' => 1, 'reasons' => ['c@d.test: refused'], 'test' => false]));
// fix 2026-11-24: who is ticked when one person already got this draft
$ticks = maint_mail_default_ticks($cands, ['admin@hotel.test' => '2026-11-24 10:00:00']);
t_eq('mail: an admin already sent this draft is unticked, an admin never sent is ticked, staff stay unticked', [2 => true, 3 => false, 1 => false], $ticks);
t_eq('mail: the sent match ignores case', false, maint_mail_default_ticks($cands, ['boss@hotel.test' => 'x'])[2] ?? null);
t_eq('mail: nothing sent yet: the administrators are ticked', [2 => true, 3 => true, 1 => false], maint_mail_default_ticks($cands, []));
t_eq('mail: after a refused send the posted ticks come back minus anyone already sent', [2 => true, 3 => false, 1 => true],
    maint_mail_default_ticks($cands, ['admin@hotel.test' => '2026-11-24 10:00:00'], ['2', '3', '1']));
t_eq('mail: posted ticks keep a non-admin and drop an unticked admin', [2 => false, 3 => false, 1 => true], maint_mail_default_ticks($cands, [], [1]));
// fix 2026-11-24: the checks run on the typed text (placeholders in), filled values are escaped, one fill pass
$d = maint_mail_drafts()['advance'];
$lt = maint_mail_vars($win, 'Inn <North> & Co', str_repeat('Long words. ', 300), $co, 'Africa/Blantyre');
[$e1, $m1] = maint_mail_render($d['title'], $d['subject'], $d['body'], $lt, $co);
t_eq('mail: a "<" in the business name and a message over 4000 characters once filled are allowed', '', $e1);
t_ok('mail: that name is escaped in the HTML and kept in the subject', is_array($m1) && strpos($m1['html'], 'Inn &lt;North&gt; &amp; Co') !== false
    && strpos($m1['html'], '<North>') === false && strpos($m1['subject'], 'Inn <North> & Co') !== false);
t_eq('mail: typed HTML is still refused', 'Write the subject and message as plain text (no HTML).', maint_mail_render('T', 'Hi {hotel}', 'Hello <b>there</b>', $lt, $co)[0]);
t_ok('mail: a typed message over 4000 characters is still refused', maint_mail_render('T', 'Hi', str_repeat('a', MAINT_MAIL_BODY_MAX + 1), $lt, $co)[0] !== '');
t_ok('mail: over the final cap once filled is refused', maint_mail_render('T', 'Hi', '{message}', array_merge($lt, ['{message}' => str_repeat('a', MAINT_MAIL_FINAL_MAX + 1)]), $co)[0] !== '');
[$e1, $m2] = maint_mail_render('T', '{hotel}', "Hi {hotel}\n\n{message}", array_merge($lt, ['{hotel}' => 'Inn {message}', '{message}' => 'Words {hotel}']), $co);
t_ok('mail: single pass: a value holding a placeholder is never filled again', $e1 === '' && $m2['subject'] === 'Inn {message}'
    && strpos($m2['text'], 'Hi Inn {message}') !== false && strpos($m2['text'], 'Words {hotel}') !== false);
t_ok('mail: the filled subject is cut to the log column size', mb_strlen(maint_mail_render('T', '{hotel}', 'b', array_merge($lt, ['{hotel}' => str_repeat('n', 400)]), $co)[1]['subject']) === 255);
$mailSrc = (string)file_get_contents($root . '/app/maint_mail.php');
t_ok('mail: the send builds through maint_mail_render (one fill, checks on the typed text)', substr_count($mailSrc, 'maint_mail_fill(') === 3
    && strpos($mailSrc, '[$err, $mail] = maint_mail_render(') !== false);
t_ok('page: the compose screen starts from the draft as written (not filled)', strpos($pageSrc, '$dtext = maint_mail_draft($pdo, $mdraft);') !== false && strpos($pageSrc, 'maint_mail_filled') === false);
t_ok('page: with "email the administrators" the nudge runs before the emails', (bool)preg_match('/backup_nudge_all\(\$pdo, \$ids\);.*?maint_mail_send\(/s', $pageSrc));
t_ok('mail: one email per person through mail_send', (bool)preg_match("/foreach \(\\\$people as \\\$p\) \{\s*try \{\s*\\\$m = mail_send\(\['to' => \[\\\$p\['email'\]\]/", $mailSrc));
t_ok('mail: the page sends only by pms_user_id (no address field)', strpos($pageSrc, 'name="people[]" value="<?= (int)$c[\'pms_user_id\'] ?>"') !== false && strpos($pageSrc, 'type="email"') === false);
t_ok('bootstrap loads maint_mail.php', strpos((string)file_get_contents($root . '/app/bootstrap.php'), "require_once __DIR__ . '/maint_mail.php';") !== false);

if (in_array('--db', $argv, true)) {
    require __DIR__ . '/../../app/bootstrap.php';
    $pdo = db();
    $temp = !maint_table_ready($pdo);
    if ($temp) {
        $ddl = (string)preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($root . '/migrations/014_maintenance.sql'));
        $pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', trim($ddl, " \n\r\t;")));
        echo "(migration 014 not applied: using a temporary cp_maintenance for this test)\n";
    }
    $pdo->beginTransaction();
    try {
        $mk = $pdo->prepare("INSERT INTO cp_tenants (slug, name, domain, paid_until, modules) VALUES (?, ?, ?, CURDATE(), ?)");
        $mk->execute(['zz-maint-a', 'Maint A', 'maint-a.invalid', json_encode(['bookings', 'finance'])]);
        $a = (int)$pdo->lastInsertId();
        $mk->execute(['zz-maint-b', 'Maint B', 'maint-b.invalid', json_encode(['bookings', 'finance'])]);
        $b2 = (int)$pdo->lastInsertId();
        $t0 = time();
        [$err, $clean] = maint_validate(['scope' => 'both', 'announce_from' => $t0, 'starts_at' => $t0 + 3600, 'ends_at' => $t0 + 7200, 'message' => ''], $t0);
        t_eq('db: valid', [], $err);
        [$e1, $ids] = maint_schedule($pdo, [$a, $b2], $clean, 0, $t0);
        t_ok('db: one window per business', $e1 === '' && count($ids) === 2);
        [$e1, $ids2] = maint_schedule($pdo, [$a], $clean, 0, $t0);
        t_ok('db: an overlapping window is refused', $e1 !== '' && $ids2 === [] && strpos($e1, 'Maint A') !== false);
        [$e1] = maint_schedule($pdo, [$a, 99999999], $clean, 0, $t0);
        t_ok('db: a business that does not exist: nothing saved', $e1 !== '');
        t_eq('db: still two windows', 2, (int)$pdo->query("SELECT COUNT(*) FROM cp_maintenance WHERE tenant_id IN ($a, $b2)")->fetchColumn());
        $aud = (int)$pdo->query("SELECT COUNT(*) FROM cp_audit_log WHERE action = 'maintenance_scheduled' AND tenant_id IN ($a, $b2)")->fetchColumn();
        t_eq('db: each one audited', 2, $aud);
        $blk = maint_answer_block($pdo, $a, $t0);
        t_ok('db: the answer carries the window with the generic message', is_array($blk) && $blk['id'] === $ids[0] && $blk['scope'] === 'both'
            && $blk['message'] === maint_default_message($pdo));
        t_ok('db: sent_at stamped', maint_find($pdo, $ids[0])['sent_at'] !== null);
        maint_store_seen($pdo, $b2, $ids[0], $t0); // another business's window id: ignored
        t_eq('db: a window of another business is never marked seen', null, maint_find($pdo, $ids[0])['hotel_seen_at']);
        maint_store_seen($pdo, $a, $ids[0], $t0 + 5);
        maint_store_seen($pdo, $a, $ids[0], $t0 + 9);
        t_eq('db: the first sighting is kept', $t0 + 5, (int)maint_find($pdo, $ids[0])['hotel_seen_at']);
        // the generic message
        t_eq('db: save a generic message', '', maint_save_default_message($pdo, 'Our own words.', 0));
        t_eq('db: it is used', 'Our own words.', maint_answer_block($pdo, $a, $t0)['message']);
        t_ok('db: an empty one is refused', maint_save_default_message($pdo, '  ', 0) !== '');
        t_eq('db: revert', '', maint_save_default_message($pdo, null, 0));
        t_eq('db: the built-in one is back', maint_builtin_message(), maint_default_message($pdo));
        // End now / Cancel
        [$e1] = maint_change($pdo, $ids[0], 'end', 0, $t0);
        t_ok('db: End now refused before the start', $e1 !== '');
        [$e1, $tid] = maint_change($pdo, $ids[0], 'cancel', 0, $t0);
        t_ok('db: Cancel before the start', $e1 === '' && $tid === $a);
        t_eq('db: cancelled: nothing sent (the business comes back online)', null, maint_answer_block($pdo, $a, $t0));
        $pdo->prepare('UPDATE cp_maintenance SET starts_at = ?, announce_from = ?, message = ? WHERE id = ?')->execute([$t0 - 60, $t0 - 120, 'Own words', $ids[1]]);
        [$e1] = maint_change($pdo, $ids[1], 'cancel', 0, $t0);
        t_ok('db: Cancel refused while running', $e1 !== '');
        [$e1] = maint_change($pdo, $ids[1], 'generic', 0, $t0);
        t_ok('db: Use the generic message', $e1 === '' && maint_find($pdo, $ids[1])['message'] === null);
        [$e1, $tid] = maint_change($pdo, $ids[1], 'end', 0, $t0);
        $r = maint_find($pdo, $ids[1]);
        t_ok('db: End now: ended, ends_at = now', $e1 === '' && $tid === $b2 && $r['state'] === 'ended' && (int)$r['ends_at'] === $t0 && (int)$r['ended_at'] === $t0);
        t_eq('db: ended: nothing sent', null, maint_answer_block($pdo, $b2, $t0));
        [$e1] = maint_change($pdo, 99999999, 'end', 0, $t0);
        t_ok('db: an unknown window is refused in plain words', $e1 === 'That window no longer exists.');
        [$e1, $ids3] = maint_schedule($pdo, [$a], $clean, 0, $t0);
        t_ok('db: after a cancel the same time can be booked again', $e1 === '' && count($ids3) === 1);
    } finally {
        $pdo->rollBack();
        if ($temp) {
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS cp_maintenance');
        }
    }
    t_eq('db: nothing kept', 0, (int)$pdo->query("SELECT COUNT(*) FROM cp_tenants WHERE slug IN ('zz-maint-a', 'zz-maint-b')")->fetchColumn());
}

t_done('maintenance_test');
