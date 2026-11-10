<?php
// HMS staff and permissions from the panel (app/hms.php, 2026-11-10).
// Pure tests always run. With --db a smoke test runs on the real database inside ONE transaction that is rolled back:
// it queues, sends, confirms and cancels changes for a made-up business and never touches any other table's rows.
//   php scripts/tests/hms_test.php        pure tests
//   php scripts/tests/hms_test.php --db   pure tests + rolled-back database smoke test
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/domain.php';
require __DIR__ . '/../../app/hms.php';

$catalogIn = [
    'roles' => [
        ['key' => 'admin', 'label' => 'Administrator', 'level' => 100, 'defaults' => null],
        ['key' => 'manager', 'label' => 'Manager', 'level' => 80, 'defaults' => ['dashboard', 'bookings', 'reports']],
        ['key' => 'receptionist', 'label' => 'Receptionist', 'level' => 40, 'defaults' => ['dashboard', 'bookings']],
        ['key' => 'BAD KEY', 'label' => 'x'],
    ],
    'permissions' => [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'category' => 'Core'],
        ['key' => 'bookings', 'label' => 'View bookings', 'category' => 'Reservations'],
        ['key' => 'reports', 'label' => 'Reports', 'category' => 'Finance'],
        ['key' => 'user_management', 'label' => 'Users', 'category' => 'Admin'],
        'junk',
    ],
];
$cat = hms_clean_catalog($catalogIn);
t_ok('catalog cleaned', $cat !== null);
t_eq('bad role key dropped', ['admin', 'manager', 'receptionist'], array_keys($cat['roles']));
t_eq('admin keeps "all" (null defaults)', null, $cat['roles']['admin']['defaults']);
t_eq('permissions kept in order', ['dashboard', 'bookings', 'reports', 'user_management'], array_keys($cat['permissions']));
t_eq('catalog without permissions is unusable', null, hms_clean_catalog(['roles' => $catalogIn['roles'], 'permissions' => []]));
t_eq('not an array', null, hms_clean_catalog('x'));

$staffIn = [
    ['id' => 1, 'username' => 'boss', 'email' => 'boss@hotel.mw', 'full_name' => 'The Boss', 'role' => 'admin', 'is_active' => 1, 'last_login' => '2026-11-10 08:00:00'],
    ['id' => 2, 'username' => 'jbanda', 'email' => 'j@hotel.mw', 'full_name' => 'J Banda', 'role' => 'receptionist', 'is_active' => 1, 'last_login' => 'yesterday',
        'grants' => ['reports', 'reports', 'Bad Key'], 'denies' => ['bookings']],
    ['id' => 3, 'username' => 'old', 'email' => '', 'full_name' => '', 'role' => 'manager', 'is_active' => 0],
    ['id' => 2, 'username' => 'dupe'],
    ['id' => 0, 'username' => 'zero'],
    ['id' => 9, 'username' => ''],
    ['id' => '7', 'username' => 'string-id'],
];
$staff = hms_clean_staff($staffIn);
t_eq('bad rows dropped (duplicate id, id 0, no username, string id)', [1, 2, 3], array_column($staff, 'pms_user_id'));
t_eq('odd last_login dropped', null, $staff[1]['last_login']);
t_eq('grants cleaned and unique', ['reports'], $staff[1]['grants']);
t_eq('inactive kept', 0, $staff[2]['is_active']);
t_eq('no password field ever kept', false, array_key_exists('password_hash', $staff[0]));

t_eq('effective: defaults + grants - denies', ['dashboard', 'reports'], hms_effective_permissions($cat, $staff[1]));
t_eq('effective: admin has all', ['dashboard', 'bookings', 'reports', 'user_management'], hms_effective_permissions($cat, $staff[0]));
t_eq('effective: unknown role = nothing but grants', ['bookings'], hms_effective_permissions($cat, ['role' => 'ghost', 'grants' => ['bookings'], 'denies' => []]));
t_eq('overrides: only differences from the role', ['bookings' => 0, 'reports' => 1], hms_permission_overrides($cat, 'receptionist', ['dashboard', 'reports']));
t_eq('overrides: user_management never granted', [], hms_permission_overrides($cat, 'receptionist', ['dashboard', 'bookings', 'user_management']));
t_eq('overrides: nothing ticked removes the defaults', ['dashboard' => 0, 'bookings' => 0], hms_permission_overrides($cat, 'receptionist', []));
t_eq('groups by category', ['Core' => ['dashboard'], 'Reservations' => ['bookings'], 'Finance' => ['reports'], 'Admin' => ['user_management']], hms_permission_groups($cat));
t_eq('one active admin', 1, hms_active_admins($cat, $staff));

t_eq('results cleaned', [['id' => 5, 'ok' => true, 'message' => 'Done'], ['id' => 6, 'ok' => false, 'message' => '']],
    hms_clean_results([['id' => 5, 'ok' => true, 'message' => 'Done'], ['id' => 6, 'ok' => false], ['id' => '7', 'ok' => true], ['id' => 8, 'ok' => 1], ['id' => 5, 'ok' => true, 'message' => 'Done']]));
t_eq('username rules', ['', '', 'ab_c', '', '', 'j.banda@x-1'], [hms_clean_username('ab'), hms_clean_username('a b c'), hms_clean_username(' ab_c '),
    hms_clean_username(['x']), hms_clean_username('<script>'), hms_clean_username('j.banda@x-1')]);
t_eq('multibyte usernames refused for new accounts', '', hms_clean_username('jöhn'));

// --- Validating changes ---
$v = static fn(string $k, array $in) => hms_validate_command($k, $in, $cat, $staff);
t_eq('no list yet: nothing can be changed', 'This HMS has not sent its staff list yet, so nothing can be changed from here.', hms_validate_command('deactivate', ['username' => 'jbanda'], null, [])[0]);
t_eq('unknown kind', 'Unknown change.', $v('delete', ['username' => 'jbanda'])[0]);
[$e, $r] = $v('deactivate', ['username' => 'JBANDA']);
t_eq('deactivate ok (username any case)', '', $e);
t_eq('deactivate carries the HMS id and the stored username', ['deactivate', 'jbanda', 2], [$r['kind'], $r['username'], $r['pms_user_id']]);
t_eq('the last active admin cannot be deactivated', 'This is the hotel\'s last active administrator: add or reactivate another one first.', $v('deactivate', ['username' => 'boss'])[0]);
t_eq('the last active admin cannot be demoted', 'This is the hotel\'s last active administrator: their role cannot be lowered.', $v('role', ['username' => 'boss', 'role' => 'manager'])[0]);
t_eq('already inactive', 'Already deactivated.', $v('deactivate', ['username' => 'old'])[0]);
t_eq('reactivate', '', $v('activate', ['username' => 'old'])[0]);
t_eq('reactivate an active one', 'Already active.', $v('activate', ['username' => 'jbanda'])[0]);
t_eq('unknown person', 'That person is not in the last staff list from this HMS.', $v('deactivate', ['username' => 'nobody'])[0]);
[$e, $r] = $v('role', ['username' => 'jbanda', 'role' => 'manager']);
t_eq('role change', ['', ['role' => 'manager']], [$e, $r['data']]);
t_eq('role must exist', 'Choose a role.', $v('role', ['username' => 'jbanda', 'role' => 'pilot'])[0]);
t_eq('same role', 'That is already their role.', $v('role', ['username' => 'jbanda', 'role' => 'receptionist'])[0]);
$two = $staff;
$two[] = ['pms_user_id' => 4, 'username' => 'boss2', 'email' => '', 'full_name' => '', 'role' => 'admin', 'is_active' => 1, 'last_login' => null, 'grants' => [], 'denies' => []];
t_eq('with two admins one may be deactivated', '', hms_validate_command('deactivate', ['username' => 'boss'], $cat, $two)[0]);
[$e, $r] = $v('permissions', ['username' => 'jbanda', 'permissions' => ['dashboard', 'bookings']]);
t_eq('permissions back to the role set = no own changes', ['', []], [$e, (array)$r['data']['overrides']]);
t_eq('the role travels with permissions (the HMS refuses if it changed there)', 'receptionist', $r['data']['role']);
t_eq('permissions summary counts', 'Permissions for J Banda (jbanda): 1 added, 1 removed', $r['summary']);
t_eq('no change refused', 'Nothing changed.', $v('permissions', ['username' => 'jbanda', 'permissions' => ['dashboard', 'reports']])[0]);
t_eq('admin permissions cannot be set', 'Administrators have every permission; change their role instead.', $v('permissions', ['username' => 'boss', 'permissions' => []])[0]);
[$e, $r] = $v('create', ['username' => 'newbie', 'full_name' => 'New Person', 'email' => 'NEW@hotel.mw', 'role' => 'receptionist']);
t_eq('create ok, email lower-cased, no password anywhere', ['', ['full_name' => 'New Person', 'email' => 'new@hotel.mw', 'role' => 'receptionist']], [$e, $r['data']]);
t_eq('create: username taken', 'That username is already used in this HMS.', $v('create', ['username' => 'JBanda', 'full_name' => 'X', 'email' => 'x@hotel.mw', 'role' => 'manager'])[0]);
t_eq('create: email taken', 'Another staff member already uses this email in this HMS.', $v('create', ['username' => 'other', 'full_name' => 'X', 'email' => 'J@hotel.mw', 'role' => 'manager'])[0]);
t_eq('create: bad username', 'The username needs 3 to 100 characters: letters, numbers, dot, dash, underscore or @, no spaces.', $v('create', ['username' => 'a b', 'full_name' => 'X', 'email' => 'x@hotel.mw', 'role' => 'manager'])[0]);
t_eq('create: name too long in bytes', 'The full name is required (at most 255 characters).', $v('create', ['username' => 'abc', 'full_name' => str_repeat('é', 200), 'email' => 'x@hotel.mw', 'role' => 'manager'])[0]);
t_eq('create: email required', 'A valid email is required: the HMS sends the invitation there.', $v('create', ['username' => 'abc', 'full_name' => 'X', 'email' => '', 'role' => 'manager'])[0]);
t_eq('invite needs an email', 'This person has no valid email in the HMS.', $v('invite', ['username' => 'old'])[0]);

// --- How a change travels and reads ---
$p = hms_command_payload(['id' => 7, 'kind' => 'role', 'username' => 'jbanda', 'pms_user_id' => 2, 'data' => '{"role":"manager"}']);
t_eq('payload', ['id' => 7, 'kind' => 'role', 'username' => 'jbanda', 'user_id' => 2, 'data' => ['role' => 'manager']], $p);
t_eq('empty data travels as an object', '{}', json_encode(hms_command_payload(['id' => 1, 'kind' => 'activate', 'username' => 'x', 'pms_user_id' => 1, 'data' => '[]'])['data']));
t_eq('state: waiting', 'Waiting for the HMS to check in', hms_command_state(['result' => null, 'sent_at' => null]));
t_eq('state: sent', 'Sent to the HMS, waiting for it to confirm', hms_command_state(['result' => null, 'sent_at' => '2026-11-10 10:00:00']));
t_eq('state: done', 'Done', hms_command_state(['result' => 'ok', 'result_message' => '']));
t_eq('state: failed', 'Not done: last admin', hms_command_state(['result' => 'failed', 'result_message' => 'last admin']));

if (in_array('--db', $argv, true)) {
    require __DIR__ . '/../../app/bootstrap.php';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO cp_tenants (slug, name, domain, paid_until) VALUES ('zz-hms-test', 'HMS test', 'hms-test.invalid', CURDATE())")->execute();
        $tid = (int)$pdo->lastInsertId();
        $t = tenant_find($pdo, $tid);
        t_eq('db: no catalog yet', null, hms_catalog($t));
        $acked = hms_store_report($pdo, $tid, [], $staff, $cat);
        $t = tenant_find($pdo, $tid);
        t_eq('db: catalog stored', array_keys($cat['roles']), array_keys(hms_catalog($t)['roles']));
        t_ok('db: synced time set', !empty($t['hms_synced_at']));
        $rows = hms_staff($pdo, $tid);
        t_eq('db: staff stored, active first, then by name', ['jbanda', 'boss', 'old'], array_column($rows, 'username'));
        t_eq('db: grants round-trip', ['reports'], $rows[0]['grants']);

        [, $r1] = hms_validate_command('deactivate', ['username' => 'jbanda'], hms_catalog($t), $rows);
        $c1 = hms_queue($pdo, $tid, $r1, null);
        [, $r2] = hms_validate_command('role', ['username' => 'old', 'role' => 'receptionist'], hms_catalog($t), $rows);
        $c2 = hms_queue($pdo, $tid, $r2, null);
        $ans = hms_answer_fields($pdo, $t);
        t_eq('db: both in the answer, oldest first', [$c1, $c2], array_column($ans['staff_commands'], 'id'));
        t_eq('db: ones the HMS already ran are not sent again', [$c2], array_column(hms_answer_fields($pdo, $t, $c1)['staff_commands'], 'id'));
        t_eq('db: no fresh list asked yet', 0, $ans['staff_list_requested_at']);
        $sent = $pdo->query('SELECT COUNT(*) FROM cp_hms_commands WHERE tenant_id = ' . $tid . ' AND sent_at IS NOT NULL')->fetchColumn();
        t_eq('db: marked sent', 2, (int)$sent);

        t_ok('db: cancel the second', hms_cancel($pdo, $tid, $c2, null));
        t_ok('db: cannot cancel twice', !hms_cancel($pdo, $tid, $c2, null));
        t_eq('db: cancelled one leaves the answer', [$c1], array_column(hms_answer_fields($pdo, $t)['staff_commands'], 'id'));

        $staff2 = $staff;
        $staff2[1]['is_active'] = 0;
        $acked = hms_store_report($pdo, $tid, [['id' => $c1, 'ok' => true, 'message' => ''], ['id' => $c2, 'ok' => true, 'message' => 'Applied before the cancel arrived'],
            ['id' => 999999999, 'ok' => true, 'message' => '']], $staff2, null);
        t_eq('db: results recorded for this business only', [$c1, $c2], $acked);
        $st = $pdo->prepare('SELECT result, result_message FROM cp_hms_commands WHERE id = ?');
        $st->execute([$c2]);
        t_eq('db: a cancelled change the HMS had applied says so', ['result' => 'ok', 'result_message' => 'Applied before the cancel arrived'], $st->fetch());
        t_eq('db: nothing left to send', [], hms_answer_fields($pdo, $t)['staff_commands']);
        t_eq('db: catalog kept when a report has none', array_keys($cat['permissions']), array_keys(hms_catalog(tenant_find($pdo, $tid))['permissions']));
        t_eq('db: list replaced', 0, (int)hms_staff($pdo, $tid)[2]['is_active'] + (int)array_column(hms_staff($pdo, $tid), 'is_active', 'username')['jbanda']);

        $pdo->prepare("UPDATE cp_hms_commands SET created_at = NOW() - INTERVAL 20 DAY, done_at = NULL, result = NULL WHERE id = ?")->execute([$c1]);
        t_eq('db: an old change lapses and is never sent', [], hms_answer_fields($pdo, $t)['staff_commands']);
        $st->execute([$c1]);
        t_eq('db: lapsed', 'lapsed', $st->fetch()['result']);

        hms_request_list($pdo, $tid, null);
        t_ok('db: a fresh list request travels', hms_answer_fields($pdo, tenant_find($pdo, $tid))['staff_list_requested_at'] > 0);

        // Authentication: same rule as the licence answer.
        $pdo->prepare('UPDATE cp_tenants SET api_key_hash = ? WHERE id = ?')->execute([password_hash('secret-key-123', PASSWORD_DEFAULT), $tid]);
        t_ok('db: right key and domain', hms_authenticate($pdo, ['tenant' => 'zz-hms-test', 'domain' => 'https://www.hms-test.invalid/', 'key' => 'secret-key-123']) !== null);
        t_ok('db: wrong key', hms_authenticate($pdo, ['tenant' => 'zz-hms-test', 'domain' => 'hms-test.invalid', 'key' => 'nope-nope-nope']) === null);
        t_ok('db: wrong domain', hms_authenticate($pdo, ['tenant' => 'zz-hms-test', 'domain' => 'evil.invalid', 'key' => 'secret-key-123']) === null);
        t_ok('db: unknown tenant', hms_authenticate($pdo, ['tenant' => 'nobody-here', 'domain' => 'hms-test.invalid', 'key' => 'secret-key-123']) === null);
        hms_store_error($pdo, tenant_find($pdo, $tid), 'The staff report was not accepted (HTTP 413, 200000 bytes).');
        $t = tenant_find($pdo, $tid);
        t_eq('db: a staff-step problem is kept with its time', ['The staff report was not accepted (HTTP 413, 200000 bytes).', true], [$t['hms_error'], !empty($t['hms_error_at'])]);
        hms_store_error($pdo, $t, '');
        $t = tenant_find($pdo, $tid);
        t_eq('db: cleared when the HMS says the step finished', [null, null], [$t['hms_error'], $t['hms_error_at']]);
    } finally {
        $pdo->rollBack();
    }
    $left = (int)$pdo->query("SELECT COUNT(*) FROM cp_tenants WHERE slug = 'zz-hms-test'")->fetchColumn();
    t_eq('db: nothing kept', 0, $left);
}

t_done('hms_test');
