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
t_ok('nav: Maintenance link', strpos((string)file_get_contents($root . '/app/view.php'), 'href="maintenance.php"') !== false);
t_ok('migration 014: no ";" inside a statement (migrate.php splits on it)', (static function (string $sql): bool {
    $sql = (string)preg_replace('/^\s*--.*$/m', '', $sql);
    return count(array_filter(array_map('trim', explode(';', $sql)))) === 1;
})((string)file_get_contents($root . '/migrations/014_maintenance.sql')));

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
