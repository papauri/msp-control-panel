<?php
// HMS staff for one business (owner 2026-11-10, app/hms.php): who can sign in to its HMS, their roles and permissions.
// ?id=TENANT lists them; &user=PMS_USER_ID manages one person. Every change is queued, carried in the next signed licence
// answer and confirmed by the HMS; the page says plainly what is still waiting. Works wherever the HMS is hosted.
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);
$adminId = (int)$admin['id'];

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$tenant = $id > 0 ? tenant_find($pdo, $id) : null;
if (!$tenant) {
    flash_set('error', 'Choose a business first.');
    header('Location: index.php');
    exit;
}
$tid = (int)$tenant['id'];

/** Back to this page, then nudge the HMS to check in now (after the redirect is sent, so nobody waits). */
function staff_back(int $tenantId, string $query = '', bool $nudge = false): void
{
    header('Location: staff.php?id=' . $tenantId . $query);
    if ($nudge) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        tenant_nudge(db(), $tenantId);
    }
    exit;
}

$catalog = hms_catalog($tenant);
$staff = hms_staff($pdo, $tid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $s = static fn(string $k): string => isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : '';
    $action = $s('action');
    $back = ctype_digit($s('user')) ? '&user=' . (int)$s('user') : '';
    if ($action === 'refresh') {
        hms_request_list($pdo, $tid, $adminId);
        flash_set('ok', 'Asked the HMS for a fresh staff list. It arrives at its next check-in (usually within a minute).');
        staff_back($tid, $back, true);
    } elseif ($action === 'cancel') {
        $ok = ctype_digit($s('command')) && hms_cancel($pdo, $tid, (int)$s('command'), $adminId);
        flash_set($ok ? 'ok' : 'error', $ok ? 'Cancelled. If the HMS had already made the change, its next report says so.' : 'That change is already finished.');
        staff_back($tid, $back);
    } elseif ($action === 'queue') {
        if (!billing_once_consume('hms_' . $tid, $s('once'))) {
            flash_set('info', 'This change was already sent (the form was sent twice). See Changes below.');
            staff_back($tid, $back);
        }
        $kind = $s('kind');
        $in = ['username' => $s('username'), 'role' => $s('role'), 'full_name' => $s('full_name'), 'email' => $s('email'),
            'permissions' => isset($_POST['perm']) && is_array($_POST['perm']) ? array_values(array_filter($_POST['perm'], 'is_string')) : []];
        [$err, $row] = hms_validate_command($kind, $in, $catalog, $staff);
        if ($err !== '' || $row === null) {
            flash_set('error', $err !== '' ? $err : 'That change could not be made.');
            staff_back($tid, $back);
        }
        hms_queue($pdo, $tid, $row, $adminId);
        flash_set('ok', $row['summary'] . ': sent to the HMS. It is applied at its next check-in (usually within a minute or two; several changes made '
            . 'one after another may wait for the next one) and shows as Done below once the HMS confirms it.');
        staff_back($tid, $back, true);
    }
    staff_back($tid, $back);
}

// The Assistant set-up (kind ai_setup) shares the queue but is shown on its own page (public/ai.php).
$commands = array_values(array_filter(hms_commands($pdo, $tid, 40), static fn($c) => (string)$c['kind'] !== AI_KIND));
$waiting = array_values(array_filter($commands, static fn($c) => $c['done_at'] === null));
$lastCheck = hms_last_check($pdo, $tid);
$userId = isset($_GET['user']) && ctype_digit((string)$_GET['user']) ? (int)$_GET['user'] : 0;
$person = null;
foreach ($staff as $p) {
    if ((int)$p['pms_user_id'] === $userId) {
        $person = $p;
    }
}
$roleLabel = static fn(string $r): string => (string)($catalog['roles'][$r]['label'] ?? ($r !== '' ? ucwords(str_replace('_', ' ', $r)) : '-'));
$when = static fn(?string $dt): string => $dt ? e(billing_date(substr($dt, 0, 10)) . ' ' . substr($dt, 11, 5)) : view_empty();
$once = static fn(): string => '<input type="hidden" name="once" value="' . e(billing_once_issue('hms_' . $tid)) . '">';
$hidden = static fn(int $user = 0): string => csrf_field() . ($user > 0 ? '<input type="hidden" name="user" value="' . $user . '">' : '');
$roleOptions = static function (string $selected = '') use ($catalog): string {
    $h = '<option value="">Choose&hellip;</option>';
    foreach ($catalog['roles'] ?? [] as $k => $r) {
        $h .= '<option value="' . e($k) . '"' . ($k === $selected ? ' selected' : '') . '>' . e($r['label']) . '</option>';
    }
    return $h;
};

view_head('Staff: ' . $tenant['name']);
view_flash();
echo '<h1>HMS staff: ' . e($tenant['name']) . '</h1>'
    . '<p class="muted"><a href="tenant.php?id=' . $tid . '">&larr; Back to the business</a></p>';

echo '<section class="card"><h2>How changes reach the HMS</h2>'
    . '<dl class="facts">'
    . view_fact('Staff list received', $when($tenant['hms_synced_at'] ?? null))
    . view_fact('Last check-in', $when($lastCheck))
    . view_fact('Changes waiting', e((string)count($waiting)))
    . view_fact('Staff', $catalog ? e((string)count($staff)) . ' <span class="muted">(' . e((string)count(array_filter($staff, static fn($p) => (int)$p['is_active'] === 1))) . ' active)</span>' : view_empty())
    . '</dl>'
    . '<p class="muted facts-note">The panel never logs into the hotel\'s server. Each change goes out in the HMS\'s next signed licence answer, wherever it is hosted. '
    . 'The panel asks it to check in straight away, so it usually lands within a minute, otherwise at its next check-in (hourly while staff use it). '
    . 'A change shows as Done only once the HMS confirms it. Passwords never travel: new staff get the HMS\'s own invitation email.</p>'
    . '<form method="post" action="staff.php?id=' . $tid . '" class="spaced">' . $hidden($userId) . '<input type="hidden" name="action" value="refresh">'
    . '<button type="submit" class="secondary">Ask for a fresh staff list now</button></form></section>';

if (!empty($tenant['hms_error'])) {
    echo '<p class="flash flash-error">The HMS reported a problem with its staff step'
        . (!empty($tenant['hms_error_at']) ? ' (' . $when((string)$tenant['hms_error_at']) . ')' : '') . ': ' . e($tenant['hms_error']) . '</p>';
}
if ($catalog === null) {
    echo '<section class="card"><h2>No staff list yet</h2><p>This HMS has not sent its staff list. That needs the HMS update that adds staff management '
        . '(November 2026) and one check-in after it. Then the list, roles and permissions show here.</p></section>';
} elseif ($person !== null) {
    // ---- One person
    $p = $person;
    $pid = (int)$p['pms_user_id'];
    $isAll = hms_role_is_all($catalog, (string)$p['role']);
    $isSuper = hms_is_super_admin($p);
    $limited = hms_is_limited_admin($catalog, $p);
    $eff = hms_effective_permissions($catalog, $p);
    $defaults = $catalog['roles'][$p['role']]['defaults'] ?? [];
    $offLabels = array_map(static fn($k) => e((string)($catalog['permissions'][$k]['label'] ?? $k)), $limited ? $p['denies'] : []);
    $uHidden = $hidden($pid) . $once() . '<input type="hidden" name="action" value="queue"><input type="hidden" name="username" value="' . e($p['username']) . '">';
    $act = 'staff.php?id=' . $tid . '&amp;user=' . $pid;
    echo '<section class="card"><h2>' . e($p['full_name'] !== '' ? $p['full_name'] : $p['username']) . '</h2>'
        . '<p class="muted"><a href="staff.php?id=' . $tid . '">&larr; All staff</a></p>'
        . '<dl class="facts">'
        . view_fact('Username', e($p['username']))
        . view_fact('Email', $p['email'] !== '' ? e($p['email']) : view_empty())
        . view_fact('Role', e($roleLabel((string)$p['role'])) . ($isSuper ? ' <span class="pill pill-trial">Super Admin</span>' : ($limited ? ' <span class="tag">Limited</span>' : '')))
        . view_fact('Status', (int)$p['is_active'] === 1 ? '<span class="pill pill-active">Active</span>' : '<span class="pill pill-suspended">Deactivated</span>')
        . view_fact('Last sign-in', $when($p['last_login']))
        . view_fact('Permissions', ($isSuper || ($isAll && !$limited)) ? 'All' : e(count($eff) . ' of ' . count($catalog['permissions'])))
        . '</dl>';
    if ($isSuper) {
        echo '<p class="muted">This is the hotel\'s super admin, its top administrator. Only another super admin can change them, on the hotel\'s Users page, '
            . 'so nothing about them can be changed from here.</p></section>';
    } else {
        echo '<div class="tools">';
        if ((int)$p['is_active'] === 1) {
            echo '<form method="post" action="' . $act . '" class="tool tool-warn js-once" data-confirm="Deactivate ' . e($p['username']) . '? They are signed out and cannot sign in until reactivated.">' . $uHidden
                . '<input type="hidden" name="kind" value="deactivate"><h3>Deactivate</h3><p class="muted">Signs them out and stops them signing in. Nothing is deleted; '
                . 'the hotel\'s administrator can undo it.</p><div class="tool-fields"></div><button type="submit" class="danger">Deactivate</button></form>';
        } else {
            echo '<form method="post" action="' . $act . '" class="tool js-once">' . $uHidden
                . '<input type="hidden" name="kind" value="activate"><h3>Reactivate</h3><p class="muted">They can sign in again with their own password.</p>'
                . '<div class="tool-fields"></div><button type="submit">Reactivate</button></form>';
        }
        if ($limited) {
            echo '<div class="tool"><h3>Role</h3><p class="muted">A super admin has switched off some of this administrator\'s permissions, so only a super admin '
                . 'can change their role, on the hotel\'s Users page.</p><div class="tool-fields"></div>'
                . '<button type="button" class="secondary" disabled>Change role</button></div>';
        } else {
            echo '<form method="post" action="' . $act . '" class="tool js-once">' . $uHidden
                . '<input type="hidden" name="kind" value="role"><h3>Role</h3><p class="muted">Their permissions go back to the new role\'s usual set.</p>'
                . '<div class="tool-fields"><label>New role<select name="role" required>' . $roleOptions((string)$p['role']) . '</select></label></div>'
                . '<button type="submit" class="secondary">Change role</button></form>';
        }
        echo '<form method="post" action="' . $act . '" class="tool js-once">' . $uHidden
            . '<input type="hidden" name="kind" value="invite"><h3>Invitation</h3><p class="muted">Emails a new one-time link to set their password. Their current password keeps working until they use it.</p>'
            . '<div class="tool-fields"></div><button type="submit" class="secondary">Send invitation</button></form>';
        if (!$isAll) {
            echo '<form method="post" action="' . $act . '" class="tool tool-wide js-once">' . $uHidden
                . '<input type="hidden" name="kind" value="permissions"><h3>Permissions</h3><p class="muted">Ticked = allowed. '
                . '<span class="perm-changed">Marked</span> boxes differ from the usual set for ' . e($roleLabel((string)$p['role'])) . '. Saving replaces all of their own changes.</p>'
                . '<div class="tool-fields perm-grid">';
            foreach (hms_permission_groups($catalog) as $cat => $keys) {
                echo '<fieldset><legend>' . e($cat) . '</legend>';
                foreach ($keys as $k) {
                    if (in_array($k, HMS_ADMIN_ONLY_PERMS, true)) {
                        continue;
                    }
                    $on = in_array($k, $eff, true);
                    $changed = $on !== in_array($k, $defaults, true);
                    echo '<label class="check' . ($changed ? ' perm-changed' : '') . '"><input type="checkbox" name="perm[]" value="' . e($k) . '"' . ($on ? ' checked' : '') . '> '
                        . e($catalog['permissions'][$k]['label']) . '</label>';
                }
                echo '</fieldset>';
            }
            echo '</div><button type="submit">Save permissions</button></form>';
        } elseif ($limited) {
            echo '</div><p class="muted">The hotel\'s super admin has switched off these permissions for this administrator: ' . implode(', ', $offLabels)
                . '. Only a super admin can change that, on the hotel\'s Users page.</p>';
        } else {
            echo '</div><p class="muted">' . e($roleLabel((string)$p['role'])) . ' has every permission unless the hotel\'s super admin switches some off, '
                . 'on the hotel\'s Users page. That cannot be done from here.</p>';
        }
        echo ($isAll ? '' : '</div>') . '</section>';
    }
} else {
    // ---- Everyone
    echo '<section class="card"><h2>Staff</h2>';
    if (!$staff) {
        echo '<p class="muted">The HMS reported no staff.</p>';
    } else {
        echo '<div class="scroll"><table class="list"><thead><tr>' . view_th('Name', 'Full name and username.')
            . view_th('Role', 'Their role in the HMS. Super Admin: the hotel\'s top administrator, changed only by another super admin on the hotel\'s Users page. '
                . 'Limited: an administrator whose super admin switched some permissions off.', 'Their role in the HMS.')
            . view_th('Status', 'Active people can sign in.') . view_th('Last sign-in', 'Their last sign-in to the HMS.')
            . view_th('Permissions', 'How many permissions they have; changes from their role\'s usual set are counted separately. '
                . 'Administrators have all unless the hotel\'s super admin switched some off.',
                'How many permissions they have; changes from their role\'s usual set are counted separately.') . '<th></th></tr></thead><tbody>';
        foreach ($staff as $p) {
            $isSuper = hms_is_super_admin($p);
            $limited = hms_is_limited_admin($catalog, $p);
            $isAll = $isSuper || (hms_role_is_all($catalog, (string)$p['role']) && !$limited); // shows "All"
            $own = count($p['grants']) + count($p['denies']);
            echo '<tr' . ((int)$p['is_active'] !== 1 ? ' class="muted"' : '') . '>'
                . '<td data-label="Name"><a href="staff.php?id=' . $tid . '&amp;user=' . (int)$p['pms_user_id'] . '">' . e($p['full_name'] !== '' ? $p['full_name'] : $p['username']) . '</a>'
                . '<br><small class="muted">' . e($p['username']) . ($p['email'] !== '' ? ' &middot; ' . e($p['email']) : '') . '</small></td>'
                . '<td data-label="Role">' . e($roleLabel((string)$p['role']))
                . ($isSuper ? ' <span class="pill pill-trial">Super Admin</span>' : ($limited ? ' <span class="tag">Limited</span>' : '')) . '</td>'
                . '<td data-label="Status">' . ((int)$p['is_active'] === 1 ? '<span class="pill pill-active">Active</span>' : '<span class="pill pill-suspended">Deactivated</span>') . '</td>'
                . '<td data-label="Last sign-in" class="nw">' . $when($p['last_login']) . '</td>'
                . '<td data-label="Permissions">' . ($isAll ? 'All' : e(count(hms_effective_permissions($catalog, $p)) . ' of ' . count($catalog['permissions'])))
                . ($limited ? ' <span class="muted">(' . count($p['denies']) . ' switched off by the super admin)</span>'
                    : ($own > 0 && !$isAll ? ' <span class="muted">(' . $own . ' changed)</span>' : '')) . '</td>'
                . '<td data-label=""><a class="button secondary" href="staff.php?id=' . $tid . '&amp;user=' . (int)$p['pms_user_id'] . '">' . ($isSuper ? 'View' : 'Manage') . '</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '<div class="tools"><form method="post" action="staff.php?id=' . $tid . '" class="tool tool-wide js-once">' . $hidden() . $once()
        . '<input type="hidden" name="action" value="queue"><input type="hidden" name="kind" value="create">'
        . '<h3>Add a staff member</h3><p class="muted">The HMS creates the account and emails them its own invitation link to choose a password. No password is set or sent from here.</p>'
        . '<div class="tool-fields bill-grid">'
        . '<label>Full name<input type="text" name="full_name" required maxlength="255"></label>'
        . '<label>Username<small class="hint">3 or more characters, no spaces</small><input type="text" name="username" required minlength="3" maxlength="100" pattern="\S{3,100}"></label>'
        . '<label>Email<small class="hint">The invitation goes here</small><input type="email" name="email" required maxlength="255"></label>'
        . '<label>Role<select name="role" required>' . $roleOptions() . '</select></label>'
        . '</div><button type="submit">Add and send invitation</button></form></div></section>';
}

// ---- Changes
echo '<section class="card" id="changes"><h2>Changes</h2>';
if (!$commands) {
    echo '<p class="muted">No changes sent from here yet.</p>';
} else {
    echo '<div class="scroll"><table><thead><tr>' . view_th('Asked', 'When the change was made here.') . view_th('Change', 'What was asked.')
        . view_th('Where it is', 'Waiting, sent, or what the HMS answered.') . '<th></th></tr></thead><tbody>';
    foreach ($commands as $c) {
        $open = $c['done_at'] === null;
        echo '<tr><td data-label="Asked" class="nw">' . $when((string)$c['created_at']) . '</td>'
            . '<td data-label="Change">' . e($c['summary']) . '</td>'
            . '<td data-label="Where it is">' . ($c['result'] === 'ok' ? '<span class="pill pill-active">' . e(hms_command_state($c)) . '</span>'
                : ($c['result'] === 'failed' ? '<span class="pill pill-suspended">' . e(hms_command_state($c)) . '</span>'
                : ($open ? '<span class="pill pill-grace">' . e(hms_command_state($c)) . '</span>' : '<span class="muted">' . e(hms_command_state($c)) . '</span>'))) . '</td>'
            . '<td data-label="">' . ($open ? '<form method="post" action="staff.php?id=' . $tid . '" class="inline">' . $hidden($userId)
                . '<input type="hidden" name="action" value="cancel"><input type="hidden" name="command" value="' . (int)$c['id'] . '">'
                . '<button type="submit" class="secondary">Cancel</button></form>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';
echo '<script src="assets/billing.js" defer></script>';
view_foot();
