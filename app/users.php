<?php
// Panel users (owner 2026-11-09): any admin can add another by email (the email is the username) and a temporary
// password; the new person sets up their own 6-digit codes and password at first sign-in (setup.php). An admin can
// reset another admin (new temporary password, codes set up again) or remove them - never themselves and never the
// last admin. Every action is re-confirmed with the acting admin's password + a fresh code, and audited without
// secrets. All admins are equal: there are no roles.

/** @return array<int,array{id:int,email:string,setup_pending:bool,last_login_at:?string,created_at:string}> */
function users_list(PDO $pdo): array
{
    $rows = $pdo->query("SELECT id, email, totp_secret = '' AS pending, last_login_at, created_at FROM cp_admins ORDER BY email")->fetchAll();
    return array_map(static fn($r) => ['id' => (int)$r['id'], 'email' => (string)$r['email'], 'setup_pending' => (bool)$r['pending'],
        'last_login_at' => $r['last_login_at'], 'created_at' => (string)$r['created_at']], $rows);
}

function users_valid_email(string $email): bool
{
    return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Adds a user who must set up codes + a new password at first sign-in. Returns '' or the problem in words. */
function users_add(PDO $pdo, int $byId, string $email, string $tempPw, string $tempPw2): string
{
    $email = strtolower(trim($email));
    if (!users_valid_email($email)) {
        return 'Enter a valid email address. It is the new person\'s username.';
    }
    if (($p = auth_password_problem($tempPw, $tempPw2, $email)) !== '') {
        return 'Temporary password: ' . $p;
    }
    try {
        $pdo->prepare("INSERT INTO cp_admins (email, password_hash, totp_secret) VALUES (?, ?, '')")->execute([$email, auth_hash_password($tempPw)]);
    } catch (PDOException $e) {
        return $e->getCode() === '23000' ? 'There is already a user with that email.' : 'Could not add the user. Nothing was changed.';
    }
    audit_log($pdo, $byId, 'user_added', null, null, ['user_id' => (int)$pdo->lastInsertId(), 'email' => $email]);
    return '';
}

/** Gives another user a new temporary password and makes them set up codes again; ends their sessions. */
function users_reset(PDO $pdo, int $byId, int $userId, string $tempPw, string $tempPw2): string
{
    if ($userId === $byId) {
        return 'To change your own password use My account.';
    }
    $st = $pdo->prepare('SELECT email FROM cp_admins WHERE id = ?');
    $st->execute([$userId]);
    $email = $st->fetchColumn();
    if ($email === false) {
        return 'That user no longer exists.';
    }
    if (($p = auth_password_problem($tempPw, $tempPw2, (string)$email)) !== '') {
        return 'Temporary password: ' . $p;
    }
    $pdo->prepare("UPDATE cp_admins SET password_hash = ?, totp_secret = '', totp_last_step = NULL, failed_count = 0, locked_until = NULL WHERE id = ?")
        ->execute([auth_hash_password($tempPw), $userId]);
    audit_log($pdo, $byId, 'user_reset', null, null, ['user_id' => $userId, 'email' => (string)$email]);
    return '';
}

/**
 * Whether $actor may remove $target, given how many admins are set up (2-step codes done). Rows are
 * ['totp_secret' => ...] or null when missing. '' when allowed, otherwise the reason in words. Pure.
 * Only a set-up admin who still exists may remove, and never the last set-up admin: a pending account (temporary
 * password only) cannot sign in by itself, so it never counts as an admin who keeps the panel reachable.
 */
function users_remove_problem(?array $actor, ?array $target, int $setUpCount): string
{
    if ($actor === null || (string)($actor['totp_secret'] ?? '') === '') {
        return 'Your account no longer exists or is not set up, so nothing was removed.';
    }
    if ($target === null) {
        return 'That user no longer exists.';
    }
    $targetSetUp = (string)($target['totp_secret'] ?? '') !== '';
    if ($targetSetUp && $setUpCount <= 1) {
        return 'The last admin cannot be removed.';
    }
    return '';
}

/** Removes another user (never yourself, never the last set-up one). Their audit entries stay. */
function users_remove(PDO $pdo, int $byId, int $userId): string
{
    if ($userId === $byId) {
        return 'You cannot remove yourself. Ask another admin.';
    }
    $pdo->beginTransaction();
    try {
        // Locks every admin row (no index on totp_secret), so two admins removing each other at once run one after
        // the other: the second finds its own account gone and stops. There is always one set-up admin left.
        $n = (int)$pdo->query("SELECT COUNT(*) FROM cp_admins WHERE totp_secret <> '' FOR UPDATE")->fetchColumn();
        $st = $pdo->prepare('SELECT id, email, totp_secret FROM cp_admins WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
        $st->execute([$byId, $userId]);
        $actor = null;
        $target = null;
        foreach ($st->fetchAll() as $row) {
            if ((int)$row['id'] === $byId) {
                $actor = $row;
            } else {
                $target = $row;
            }
        }
        if (($problem = users_remove_problem($actor, $target, $n)) !== '') {
            $pdo->rollBack();
            return $problem;
        }
        $email = (string)$target['email'];
        $pdo->prepare('DELETE FROM cp_admins WHERE id = ?')->execute([$userId]);
        audit_log($pdo, $byId, 'user_removed', null, null, ['user_id' => $userId, 'email' => (string)$email]);
        $pdo->commit();
        return '';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('users_remove: ' . $e->getMessage());
        return 'Could not remove the user. Nothing was changed.';
    }
}

/**
 * Changes the signed-in admin's own password (after auth_reconfirm). Keeps this browser signed in and ends every
 * other session of this account.
 */
function users_change_own_password(PDO $pdo, int $id, string $pw, string $pw2): string
{
    $st = $pdo->prepare('SELECT email, password_hash, totp_secret FROM cp_admins WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        return 'Your account no longer exists.';
    }
    if (($p = auth_password_problem($pw, $pw2, (string)$row['email'])) !== '') {
        return $p;
    }
    if (password_verify($pw, (string)$row['password_hash'])) {
        return 'That is your current password. Choose a new one.';
    }
    $hash = auth_hash_password($pw);
    $pdo->prepare('UPDATE cp_admins SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
    session_regenerate_id(true);
    $_SESSION['fp'] = auth_fp($hash, (string)$row['totp_secret']);
    audit_log($pdo, $id, 'password_changed', null, null, null);
    return '';
}
