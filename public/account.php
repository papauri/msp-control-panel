<?php
// My account: change your own password (current password + a fresh code). Other browsers signed in as you are
// signed out; this one stays signed in.
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);
$me = (int)$admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $s = static fn(string $k): string => isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : '';
    if (!auth_reconfirm($pdo, $me, $s('my_password'), (string)preg_replace('/\D/', '', $s('my_code')))) {
        flash_set('error', 'Your current password or code was not accepted, so nothing was changed. Use a new code (each code works once).');
    } else {
        $err = users_change_own_password($pdo, $me, $s('password'), $s('password2'));
        flash_set($err === '' ? 'ok' : 'error', $err === '' ? 'Password changed. Any other browser signed in as you has been signed out.' : $err);
    }
    header('Location: account.php');
    exit;
}

view_head('My account');
echo view_page_head('My account');
view_flash();
?>
<div class="card">
    <p>Signed in as <strong><?= e($admin['email']) ?></strong>.</p>
    <h2>Change my password</h2>
    <form method="post" action="account.php" autocomplete="off">
        <?= csrf_field() ?>
        <label>Current password <input type="password" name="my_password" required maxlength="1024" autocomplete="current-password"></label>
        <label>6-digit code <input type="text" name="my_code" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code"></label>
        <label>New password (at least <?= (int)AUTH_MIN_PASSWORD ?> characters)
            <input type="password" name="password" required minlength="<?= (int)AUTH_MIN_PASSWORD ?>" maxlength="1024" autocomplete="new-password"></label>
        <label>New password again
            <input type="password" name="password2" required minlength="<?= (int)AUTH_MIN_PASSWORD ?>" maxlength="1024" autocomplete="new-password"></label>
        <button type="submit">Change password</button>
    </form>
    <p class="muted">Lost your phone? Ask another admin to reset you on the Users page, or see Guide section 12 F.</p>
</div>
<?php
view_foot();
