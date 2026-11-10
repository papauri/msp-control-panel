<?php
// HMS staff and permissions from the panel (owner 2026-11-10).
//
// The panel never logs into a hotel's HMS, wherever it is hosted: it only answers the HMS's own check-in. So a change made
// here is QUEUED (cp_hms_commands), carried in the next SIGNED licence answer as staff_commands, applied by the HMS (which
// checks the signature, the tenant, the domain and that each command id is new), and confirmed in the HMS's staff report
// (public/api/hms-staff.php). That report also carries the staff list: id, username, email, name, role, active, last
// sign-in and the per-user permission changes. Never a password. The panel nudges the HMS straight after a change, so it
// usually lands within a minute; otherwise at the HMS's next check-in (hourly while staff use it).
//
// Commands (data is a JSON object):
//   deactivate / activate   {}
//   role                    {role}
//   permissions             {overrides: {permission_key: 0|1}}  replaces ALL of the user's own permission changes
//   create                  {full_name, email, role}  the HMS emails its own invitation link (no password ever travels)
//   invite                  {}  a fresh invitation link
// The HMS refuses what would lock the hotel out (the last active admin) and anything for a user that no longer matches.

const HMS_KINDS = [
    'deactivate' => 'Deactivate', 'activate' => 'Reactivate', 'role' => 'Change role',
    'permissions' => 'Change permissions', 'create' => 'Add staff member', 'invite' => 'Send invitation',
];
const HMS_MAX_IN_ANSWER = 10;      // commands per licence answer (the rest follow at the next check-in)
const HMS_COMMAND_DAYS = 14;       // a change not picked up in this many days lapses: it is never applied late
const HMS_REPORT_MAX_BYTES = 524288;
const HMS_KEY_RE = '/^[a-z0-9_]{1,60}$/D';
const HMS_ADMIN_ONLY_PERMS = ['user_management']; // the HMS never lets a non-admin hold these

// ---------------------------------------------------------------------------------------------------- pure: cleaning

function hms_str($v, int $max): string
{
    if (!is_string($v) && !is_int($v)) {
        return '';
    }
    $s = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)$v) ?? '');
    return mb_substr($s, 0, $max);
}

/** A username for a NEW account: 3 to 100 of A-Z a-z 0-9 . _ @ - (what every HMS screen shows safely), or ''. */
function hms_clean_username($u): string
{
    $u = is_string($u) ? trim($u) : '';
    return preg_match('/^[A-Za-z0-9._@-]{3,100}$/D', $u) ? $u : '';
}

/** An existing account's username as the HMS reported it (any characters), trimmed, or ''. */
function hms_lookup_username($u): string
{
    $u = is_string($u) ? trim($u) : '';
    return ($u !== '' && strlen($u) <= 100) ? $u : '';
}

/** @return string[] unique valid keys, in order */
function hms_clean_keys($list, int $max = 500): array
{
    $out = [];
    if (!is_array($list)) {
        return $out;
    }
    foreach ($list as $k) {
        if (is_string($k) && preg_match(HMS_KEY_RE, $k) && !in_array($k, $out, true)) {
            $out[] = $k;
            if (count($out) >= $max) {
                break;
            }
        }
    }
    return $out;
}

/**
 * The HMS's roles and permissions as reported, cleaned. null when unusable.
 * @return array{roles:array<string,array{label:string,level:int,defaults:?array}>,permissions:array<string,array{label:string,category:string}>}|null
 */
function hms_clean_catalog($c): ?array
{
    if (!is_array($c) || !isset($c['roles'], $c['permissions']) || !is_array($c['roles']) || !is_array($c['permissions'])) {
        return null;
    }
    $roles = [];
    foreach (array_slice($c['roles'], 0, 60) as $r) {
        if (!is_array($r) || !isset($r['key']) || !is_string($r['key']) || !preg_match(HMS_KEY_RE, $r['key'])) {
            continue;
        }
        $roles[$r['key']] = [
            'label' => hms_str($r['label'] ?? $r['key'], 80) ?: $r['key'],
            'level' => is_int($r['level'] ?? null) ? max(0, min(1000, $r['level'])) : 0,
            'defaults' => array_key_exists('defaults', $r) && $r['defaults'] === null ? null : hms_clean_keys($r['defaults'] ?? []),
        ];
    }
    $perms = [];
    foreach (array_slice($c['permissions'], 0, 500) as $p) {
        if (!is_array($p) || !isset($p['key']) || !is_string($p['key']) || !preg_match(HMS_KEY_RE, $p['key'])) {
            continue;
        }
        $perms[$p['key']] = ['label' => hms_str($p['label'] ?? $p['key'], 120) ?: $p['key'], 'category' => hms_str($p['category'] ?? '', 60) ?: 'Other'];
    }
    return ($roles && $perms) ? ['roles' => $roles, 'permissions' => $perms] : null;
}

/** The staff list as reported, cleaned (at most 1000 people). Rows without a usable id or username are dropped. */
function hms_clean_staff($list): array
{
    $out = [];
    if (!is_array($list)) {
        return $out;
    }
    foreach (array_slice($list, 0, 1000) as $s) {
        if (!is_array($s) || !is_int($s['id'] ?? null) || $s['id'] <= 0 || isset($out[$s['id']])) {
            continue;
        }
        $username = hms_str($s['username'] ?? '', 100);
        if ($username === '') {
            continue;
        }
        $last = is_string($s['last_login'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s['last_login']) ? $s['last_login'] : null;
        $role = is_string($s['role'] ?? null) && preg_match(HMS_KEY_RE, $s['role']) ? $s['role'] : '';
        $out[$s['id']] = [
            'pms_user_id' => $s['id'], 'username' => $username, 'email' => hms_str($s['email'] ?? '', 255),
            'full_name' => hms_str($s['full_name'] ?? '', 255), 'role' => $role,
            'is_active' => !empty($s['is_active']) ? 1 : 0, 'last_login' => $last,
            'grants' => hms_clean_keys($s['grants'] ?? []), 'denies' => hms_clean_keys($s['denies'] ?? []),
        ];
    }
    return array_values($out);
}

/** Results of commands the HMS ran: [{id, ok, message}], cleaned. */
function hms_clean_results($list): array
{
    $out = [];
    if (!is_array($list)) {
        return $out;
    }
    foreach (array_slice($list, 0, 200) as $r) {
        if (is_array($r) && is_int($r['id'] ?? null) && $r['id'] > 0 && is_bool($r['ok'] ?? null)) {
            $out[$r['id']] = ['id' => $r['id'], 'ok' => $r['ok'], 'message' => hms_str($r['message'] ?? '', 255)];
        }
    }
    return array_values($out);
}

// ---------------------------------------------------------------------------------------------------- pure: permissions

/** Permission keys a person effectively holds: the role's defaults (all for a role with no list), then their own changes. */
function hms_effective_permissions(array $catalog, array $staff): array
{
    $all = array_keys($catalog['permissions']);
    $role = $catalog['roles'][$staff['role']] ?? null;
    if ($role !== null && $role['defaults'] === null) {
        return $all; // e.g. admin: everything, always
    }
    $have = array_flip($role['defaults'] ?? []);
    foreach ($staff['grants'] ?? [] as $k) {
        $have[$k] = true;
    }
    foreach ($staff['denies'] ?? [] as $k) {
        unset($have[$k]);
    }
    return array_values(array_filter($all, static fn($k) => isset($have[$k])));
}

/** True when this role has every permission by definition (its permissions cannot be changed). */
function hms_role_is_all(array $catalog, string $role): bool
{
    return isset($catalog['roles'][$role]) && $catalog['roles'][$role]['defaults'] === null;
}

/**
 * From the boxes ticked for a person, the changes to store against their role's defaults: {key: 1|0} only where they differ.
 * Admin-only permissions are never granted to anyone else.
 */
function hms_permission_overrides(array $catalog, string $role, array $ticked): array
{
    $defaults = array_flip($catalog['roles'][$role]['defaults'] ?? []);
    $ticked = array_flip(hms_clean_keys($ticked));
    $out = [];
    foreach (array_keys($catalog['permissions']) as $k) {
        if (in_array($k, HMS_ADMIN_ONLY_PERMS, true)) {
            continue;
        }
        $want = isset($ticked[$k]);
        if ($want !== isset($defaults[$k])) {
            $out[$k] = $want ? 1 : 0;
        }
    }
    return $out;
}

/** @return array<string,string[]> category => keys, in the HMS's own order */
function hms_permission_groups(array $catalog): array
{
    $g = [];
    foreach ($catalog['permissions'] as $k => $p) {
        $g[$p['category']][] = $k;
    }
    return $g;
}

/** Active admins (role with every permission) in a staff list. */
function hms_active_admins(array $catalog, array $staff): int
{
    $n = 0;
    foreach ($staff as $s) {
        if ((int)$s['is_active'] === 1 && hms_role_is_all($catalog, (string)$s['role'])) {
            $n++;
        }
    }
    return $n;
}

/**
 * Check a change the owner asked for, against the last list the HMS reported. Pure.
 * $in: username (or for create: username, full_name, email, role), role, permissions (ticked keys).
 * @return array{0:string,1:?array} [error, row] row = {kind, username, pms_user_id, data, summary}
 */
function hms_validate_command(string $kind, array $in, ?array $catalog, array $staff): array
{
    if (!isset(HMS_KINDS[$kind])) {
        return ['Unknown change.', null];
    }
    if ($catalog === null) {
        return ['This HMS has not sent its staff list yet, so nothing can be changed from here.', null];
    }
    $username = $kind === 'create' ? hms_clean_username($in['username'] ?? '') : hms_lookup_username($in['username'] ?? '');
    $byName = [];
    foreach ($staff as $s) {
        $byName[mb_strtolower((string)$s['username'])] = $s;
    }
    if ($kind === 'create') {
        $name = hms_str($in['full_name'] ?? '', 255);
        $email = strtolower(hms_str($in['email'] ?? '', 255));
        $role = (string)($in['role'] ?? '');
        if ($username === '') {
            return ['The username needs 3 to 100 characters: letters, numbers, dot, dash, underscore or @, no spaces.', null];
        }
        if (isset($byName[mb_strtolower($username)])) {
            return ['That username is already used in this HMS.', null];
        }
        if ($name === '' || strlen($name) > 255) {
            return ['The full name is required (at most 255 characters).', null];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['A valid email is required: the HMS sends the invitation there.', null];
        }
        foreach ($staff as $s) {
            if (strtolower((string)$s['email']) === $email) {
                return ['Another staff member already uses this email in this HMS.', null];
            }
        }
        if (!isset($catalog['roles'][$role])) {
            return ['Choose a role.', null];
        }
        return ['', ['kind' => 'create', 'username' => $username, 'pms_user_id' => null,
            'data' => ['full_name' => $name, 'email' => $email, 'role' => $role],
            'summary' => 'Add ' . $name . ' (' . $username . ') as ' . $catalog['roles'][$role]['label'] . ' and email the invitation']];
    }

    $s = $byName[mb_strtolower($username)] ?? null;
    if ($s === null) {
        return ['That person is not in the last staff list from this HMS.', null];
    }
    $who = ($s['full_name'] !== '' ? $s['full_name'] : $s['username']) . ' (' . $s['username'] . ')';
    $row = ['kind' => $kind, 'username' => (string)$s['username'], 'pms_user_id' => (int)$s['pms_user_id'], 'data' => []];
    $isAdmin = hms_role_is_all($catalog, (string)$s['role']);
    $lastAdmin = $isAdmin && (int)$s['is_active'] === 1 && hms_active_admins($catalog, $staff) <= 1;
    switch ($kind) {
        case 'deactivate':
            if ((int)$s['is_active'] !== 1) {
                return ['Already deactivated.', null];
            }
            if ($lastAdmin) {
                return ['This is the hotel\'s last active administrator: add or reactivate another one first.', null];
            }
            return ['', $row + ['summary' => 'Deactivate ' . $who]];
        case 'activate':
            if ((int)$s['is_active'] === 1) {
                return ['Already active.', null];
            }
            return ['', $row + ['summary' => 'Reactivate ' . $who]];
        case 'role':
            $role = (string)($in['role'] ?? '');
            if (!isset($catalog['roles'][$role])) {
                return ['Choose a role.', null];
            }
            if ($role === $s['role']) {
                return ['That is already their role.', null];
            }
            if ($lastAdmin && !hms_role_is_all($catalog, $role)) {
                return ['This is the hotel\'s last active administrator: their role cannot be lowered.', null];
            }
            $row['data'] = ['role' => $role];
            return ['', $row + ['summary' => 'Change ' . $who . ' to ' . $catalog['roles'][$role]['label'] . ' (their permissions go back to that role\'s usual set)']];
        case 'permissions':
            if ($isAdmin) {
                return ['Administrators have every permission; change their role instead.', null];
            }
            if (!isset($catalog['roles'][$s['role']])) {
                return ['This person\'s role is not in the HMS\'s list, so permissions cannot be set from here.', null];
            }
            $ticked = is_array($in['permissions'] ?? null) ? $in['permissions'] : [];
            // The role travels too: the HMS refuses the change if their role changed there first (the overrides would be wrong).
            $row['data'] = ['role' => (string)$s['role'], 'overrides' => (object)hms_permission_overrides($catalog, (string)$s['role'], $ticked)];
            $now = hms_effective_permissions($catalog, $s);
            $want = array_values(array_intersect(array_keys($catalog['permissions']), hms_clean_keys($ticked)));
            $want = array_values(array_diff($want, HMS_ADMIN_ONLY_PERMS));
            $add = array_diff($want, $now);
            $remove = array_diff($now, $want);
            if (!$add && !$remove) {
                return ['Nothing changed.', null];
            }
            return ['', $row + ['summary' => 'Permissions for ' . $who . ': ' . ($add ? count($add) . ' added' : '')
                . ($add && $remove ? ', ' : '') . ($remove ? count($remove) . ' removed' : '')]];
        case 'invite':
            if (!filter_var((string)$s['email'], FILTER_VALIDATE_EMAIL)) {
                return ['This person has no valid email in the HMS.', null];
            }
            return ['', $row + ['summary' => 'Email ' . $who . ' a new invitation link']];
    }
    return ['Unknown change.', null];
}

/** One command as it travels in the signed licence answer. */
function hms_command_payload(array $c): array
{
    $data = json_decode((string)($c['data'] ?? ''), true);
    return ['id' => (int)$c['id'], 'kind' => (string)$c['kind'], 'username' => (string)$c['username'],
        'user_id' => (int)($c['pms_user_id'] ?? 0), 'data' => is_array($data) && $data ? $data : new stdClass()];
}

/** Plain words for where a change is. */
function hms_command_state(array $c): string
{
    switch ((string)($c['result'] ?? '')) {
        case 'ok': return 'Done' . (($c['result_message'] ?? '') !== '' ? ': ' . $c['result_message'] : '');
        case 'failed': return 'Not done: ' . (($c['result_message'] ?? '') !== '' ? $c['result_message'] : 'the HMS refused it');
        case 'cancelled': return 'Cancelled';
        case 'lapsed': return 'Lapsed: not picked up within ' . HMS_COMMAND_DAYS . ' days';
    }
    return empty($c['sent_at']) ? 'Waiting for the HMS to check in' : 'Sent to the HMS, waiting for it to confirm';
}

// ---------------------------------------------------------------------------------------------------- database

function hms_catalog(array $tenant): ?array
{
    $raw = (string)($tenant['hms_catalog'] ?? '');
    $c = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($c) && isset($c['roles'], $c['permissions']) ? $c : null;
}

/** The last staff list from this HMS, with grants / denies decoded. */
function hms_staff(PDO $pdo, int $tenantId): array
{
    $st = $pdo->prepare('SELECT * FROM cp_hms_staff WHERE tenant_id = ? ORDER BY is_active DESC, full_name, username');
    $st->execute([$tenantId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $r['grants'] = hms_clean_keys(json_decode((string)$r['grants'], true));
        $r['denies'] = hms_clean_keys(json_decode((string)$r['denies'], true));
        $out[] = $r;
    }
    return $out;
}

function hms_commands(PDO $pdo, int $tenantId, int $limit = 30): array
{
    $st = $pdo->prepare('SELECT * FROM cp_hms_commands WHERE tenant_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
    $st->execute([$tenantId]);
    return $st->fetchAll();
}

function hms_last_check(PDO $pdo, int $tenantId): ?string
{
    $st = $pdo->prepare("SELECT MAX(checked_at) FROM cp_licence_checks WHERE tenant_id = ? AND result_status IN ('active','grace','read_only','suspended')");
    $st->execute([$tenantId]);
    $v = $st->fetchColumn();
    return $v ? (string)$v : null;
}

/** Queue a checked change (row from hms_validate_command). Audited. @return int the command id */
function hms_queue(PDO $pdo, int $tenantId, array $row, ?int $adminId): int
{
    $pdo->prepare('INSERT INTO cp_hms_commands (tenant_id, kind, username, pms_user_id, data, summary, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$tenantId, $row['kind'], $row['username'], $row['pms_user_id'], json_encode($row['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            mb_substr($row['summary'], 0, 255), $adminId]);
    $id = (int)$pdo->lastInsertId();
    audit_log($pdo, $adminId, 'hms_change_queued', $tenantId, null, ['command' => $id, 'kind' => $row['kind'], 'username' => $row['username'], 'summary' => $row['summary']]);
    return $id;
}

/** Cancel a change that has not been confirmed yet. If the HMS had already applied it, its report still records that. */
function hms_cancel(PDO $pdo, int $tenantId, int $commandId, ?int $adminId): bool
{
    $st = $pdo->prepare("UPDATE cp_hms_commands SET done_at = NOW(), result = 'cancelled' WHERE id = ? AND tenant_id = ? AND done_at IS NULL");
    $st->execute([$commandId, $tenantId]);
    if ($st->rowCount() !== 1) {
        return false;
    }
    audit_log($pdo, $adminId, 'hms_change_cancelled', $tenantId, null, ['command' => $commandId]);
    return true;
}

function hms_request_list(PDO $pdo, int $tenantId, ?int $adminId): void
{
    $pdo->prepare('UPDATE cp_tenants SET hms_list_requested_at = NOW() WHERE id = ?')->execute([$tenantId]);
    audit_log($pdo, $adminId, 'hms_list_requested', $tenantId, null, null);
}

/**
 * What goes into this tenant's signed licence answer: the changes not done yet (oldest first, at most HMS_MAX_IN_ANSWER)
 * and when the owner last asked for a fresh staff list. Changes older than HMS_COMMAND_DAYS lapse first.
 * $doneId: the last change the HMS says it ran (staff_done_id in its check-in). Only newer ones are sent, so changes it ran
 * but could not confirm yet never crowd out new ones. Ones it ran stay "sent" until its report confirms them.
 * @return array{staff_commands:array,staff_list_requested_at:int}
 */
function hms_answer_fields(PDO $pdo, array $tenant, int $doneId = 0): array
{
    $tid = (int)$tenant['id'];
    $pdo->prepare("UPDATE cp_hms_commands SET done_at = NOW(), result = 'lapsed' WHERE tenant_id = ? AND done_at IS NULL AND sent_at IS NULL AND created_at < NOW() - INTERVAL " . HMS_COMMAND_DAYS . ' DAY')
        ->execute([$tid]);
    $pdo->prepare("UPDATE cp_hms_commands SET done_at = NOW(), result = 'lapsed' WHERE tenant_id = ? AND done_at IS NULL AND id > ? AND created_at < NOW() - INTERVAL " . HMS_COMMAND_DAYS . ' DAY')
        ->execute([$tid, max(0, $doneId)]);
    $st = $pdo->prepare('SELECT * FROM cp_hms_commands WHERE tenant_id = ? AND done_at IS NULL AND id > ? ORDER BY id LIMIT ' . HMS_MAX_IN_ANSWER);
    $st->execute([$tid, max(0, $doneId)]);
    $cmds = $st->fetchAll();
    if ($cmds) {
        $ids = array_map(static fn($c) => (int)$c['id'], $cmds);
        $pdo->prepare('UPDATE cp_hms_commands SET sent_at = NOW() WHERE sent_at IS NULL AND id IN (' . implode(',', $ids) . ')')->execute();
    }
    $ask = !empty($tenant['hms_list_requested_at']) ? (int)strtotime((string)$tenant['hms_list_requested_at']) : 0;
    return ['staff_commands' => array_map('hms_command_payload', $cmds), 'staff_list_requested_at' => max(0, $ask)];
}

/**
 * Store a staff report from the HMS (already authenticated): results first, then the list and the roles/permissions.
 * @return int[] the command ids whose result was recorded
 */
function hms_store_report(PDO $pdo, int $tenantId, array $results, ?array $staff, ?array $catalog): array
{
    $acked = [];
    $own = !$pdo->inTransaction(); // the tests run it inside their own rolled-back transaction
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $up = $pdo->prepare("UPDATE cp_hms_commands SET done_at = NOW(), result = ?, result_message = ?
                             WHERE id = ? AND tenant_id = ? AND (done_at IS NULL OR result IN ('cancelled', 'lapsed'))");
        $seen = $pdo->prepare('SELECT 1 FROM cp_hms_commands WHERE id = ? AND tenant_id = ?');
        foreach ($results as $r) {
            $up->execute([$r['ok'] ? 'ok' : 'failed', $r['message'] !== '' ? $r['message'] : null, $r['id'], $tenantId]);
            $seen->execute([$r['id'], $tenantId]);
            if ($seen->fetchColumn()) {
                $acked[] = $r['id'];
            }
        }
        if ($staff !== null) {
            $pdo->prepare('DELETE FROM cp_hms_staff WHERE tenant_id = ?')->execute([$tenantId]);
            $ins = $pdo->prepare('INSERT INTO cp_hms_staff (tenant_id, pms_user_id, username, email, full_name, role, is_active, last_login, grants, denies)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($staff as $s) {
                $ins->execute([$tenantId, $s['pms_user_id'], $s['username'], $s['email'], $s['full_name'], $s['role'], $s['is_active'], $s['last_login'],
                    json_encode($s['grants']), json_encode($s['denies'])]);
            }
            $pdo->prepare('UPDATE cp_tenants SET hms_synced_at = NOW()' . ($catalog !== null ? ', hms_catalog = ?' : '') . ' WHERE id = ?')
                ->execute($catalog !== null ? [json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId] : [$tenantId]);
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $acked;
}

/** What the HMS says about its staff step in its check-in ('' = the last run finished). Written only when it changes. */
function hms_store_error(PDO $pdo, array $tenant, string $error): void
{
    $error = hms_str($error, 255);
    if ($error === (string)($tenant['hms_error'] ?? '')) {
        return;
    }
    $pdo->prepare('UPDATE cp_tenants SET hms_error = ?, hms_error_at = ? WHERE id = ?')
        ->execute([$error !== '' ? $error : null, $error !== '' ? date('Y-m-d H:i:s') : null, (int)$tenant['id']]);
}

/**
 * The same key and domain check as the licence answer (one password_verify whatever happens, so timing tells nothing).
 * @return array|null the tenant
 */
function hms_authenticate(PDO $pdo, array $in): ?array
{
    $slug = is_string($in['tenant'] ?? null) ? $in['tenant'] : '';
    $domain = is_string($in['domain'] ?? null) ? $in['domain'] : '';
    $key = is_string($in['key'] ?? null) ? $in['key'] : '';
    if ($slug === '' || strlen($slug) > 64 || $domain === '' || strlen($domain) > 255 || $key === '' || strlen($key) > 200) {
        password_verify('x', AUTH_DUMMY_HASH);
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM cp_tenants WHERE slug = ?');
    $st->execute([strtolower($slug)]);
    $t = $st->fetch() ?: null;
    $hash = ($t && !empty($t['api_key_hash'])) ? $t['api_key_hash'] : AUTH_DUMMY_HASH;
    $keyOk = password_verify($key, $hash) && $t && !empty($t['api_key_hash']);
    $domainOk = $t && hash_equals(licence_normalise_domain((string)$t['domain']), licence_normalise_domain($domain));
    return ($keyOk && $domainOk) ? $t : null;
}
