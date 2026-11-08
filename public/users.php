<?php
// Users: who can sign in to this panel. Add (email + temporary password), reset, remove. Every action needs the
// acting admin's own password and a fresh 6-digit code (auth_reconfirm). See app/users.php.
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);
$me = (int)$admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $s = static fn(string $k): string => isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : '';
    $action = $s('action');
    $userId = ctype_digit($s('user_id')) ? (int)$s('user_id') : 0;
    $err = '';
    if (!in_array($action, ['add', 'reset', 'remove'], true)) {
        http_response_code(400);
        echo 'Invalid request.';
        exit;
    }
    if (!auth_reconfirm($pdo, $me, $s('my_password'), (string)preg_replace('/\D/', '', $s('my_code')))) {
        $err = 'Your password or code was not accepted, so nothing was changed. Use a new code (each code works once).';
    } elseif ($action === 'add') {
        $err = users_add($pdo, $me, $s('email'), $s('temp_password'), $s('temp_password2'));
        $ok = 'User added. Give them the panel address, their email and the temporary password (by phone or in person, not in the same message). At first sign-in they set up their own codes and password.';
    } elseif ($action === 'reset') {
        $err = users_reset($pdo, $me, $userId, $s('temp_password'), $s('temp_password2'));
        $ok = 'User reset and signed out everywhere. Give them the new temporary password; at their next sign-in they set up codes and a password again.';
    } else {
        $err = users_remove($pdo, $me, $userId);
        $ok = 'User removed and signed out. Their past actions stay in the audit log.';
    }
    flash_set($err === '' ? 'ok' : 'error', $err === '' ? $ok : $err);
    header('Location: users.php');
    exit;
}

$users = users_list($pdo);
$reauth = static function (): string {
    return '<label>Your password <input type="password" name="my_password" required maxlength="1024" autocomplete="current-password"></label>'
        . '<label>Your 6-digit code <input type="text" name="my_code" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code"></label>';
};
$tempPw = static function (string $label): string {
    return '<label>' . e($label) . ' (at least ' . (int)AUTH_MIN_PASSWORD . ' characters)'
        . '<input type="password" name="temp_password" required minlength="' . (int)AUTH_MIN_PASSWORD . '" maxlength="1024" autocomplete="new-password"></label>'
        . '<label>Temporary password again<input type="password" name="temp_password2" required minlength="' . (int)AUTH_MIN_PASSWORD . '" maxlength="1024" autocomplete="new-password"></label>';
};

view_head('Users');
echo '<h1>Users</h1>';
view_flash();
echo '<p class="muted">Everyone here can do everything in the panel. Add only people who need it, and remove them when they no longer do.</p>';
echo '<div class="scroll"><table class="list"><thead><tr>'
    . view_th('Email', 'The person\'s sign-in name.', 'Sign-in name')
    . view_th('Status', 'Set up: signs in with password + code. Waiting: has a temporary password and has not finished the first sign-in yet.', 'Set up or waiting')
    . view_th('Last sign-in', 'The last time this person signed in.', 'Last time in')
    . view_th('Added', 'When the person was added.', 'Date added')
    . view_th('Actions', 'Reset gives a new temporary password and signs them out everywhere. Remove takes away their access.', 'Reset or remove')
    . '</tr></thead><tbody>';
foreach ($users as $u) {
    $isMe = $u['id'] === $me;
    echo '<tr><td data-label="Email">' . e($u['email']) . ($isMe ? ' <span class="tag">you</span>' : '') . '</td>'
        . '<td data-label="Status">' . ($u['setup_pending'] ? '<span class="pill pill-grace">Waiting for first sign-in</span>' : '<span class="pill pill-active">Set up</span>') . '</td>'
        . '<td data-label="Last sign-in" class="nw">' . ($u['last_login_at'] ? e($u['last_login_at']) : view_empty()) . '</td>'
        . '<td data-label="Added" class="nw">' . e(substr($u['created_at'], 0, 10)) . '</td><td data-label="Actions">';
    if ($isMe) {
        echo '<a href="account.php">My account</a>';
    } else {
        echo '<details><summary>Reset</summary><form method="post" action="users.php" autocomplete="off" class="spaced">' . csrf_field()
            . '<input type="hidden" name="action" value="reset"><input type="hidden" name="user_id" value="' . (int)$u['id'] . '">'
            . $tempPw('New temporary password for ' . $u['email']) . $reauth() . '<button type="submit">Reset this user</button></form></details>'
            . '<details><summary>Remove</summary><form method="post" action="users.php" autocomplete="off" class="spaced">' . csrf_field()
            . '<input type="hidden" name="action" value="remove"><input type="hidden" name="user_id" value="' . (int)$u['id'] . '">'
            . '<p>' . e($u['email']) . ' will no longer be able to sign in.</p>' . $reauth() . '<button type="submit" class="danger">Remove this user</button></form></details>';
    }
    echo '</td></tr>';
}
echo '</tbody></table></div>';
?>
<div class="card">
    <h2>Add a user</h2>
    <p class="muted">The email is their sign-in name. Choose a temporary password and give it to them by phone or in person. At their first sign-in they add the panel to their authenticator app and choose their own password.</p>
    <form method="post" action="users.php" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <label>Their email <input type="email" name="email" required maxlength="190" autocomplete="off"></label>
        <?= $tempPw('Temporary password') ?>
        <p class="muted">To confirm it is you:</p>
        <?= $reauth() ?>
        <button type="submit">Add user</button>
    </form>
</div>
<?php
view_foot();
