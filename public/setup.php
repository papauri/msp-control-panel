<?php
// First sign-in (a new user, or one whose account an admin reset): add the panel to an authenticator app, prove it
// works with a code, and replace the temporary password. Reached only from login.php with the right password; the
// setup session never carries admin_id, so no other page opens until this is done (auth_current refuses it).
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();

if (auth_current($pdo) !== null) {
    header('Location: index.php');
    exit;
}
$setup = auth_setup_current();
if ($setup === null) {
    foreach (['setup_admin_id', 'setup_email', 'setup_at', 'setup_secret'] as $k) {
        unset($_SESSION[$k]);
    }
    header('Location: login.php?timeout=1');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $code = isset($_POST['code']) && is_string($_POST['code']) ? $_POST['code'] : '';
    $pw = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $pw2 = isset($_POST['password2']) && is_string($_POST['password2']) ? $_POST['password2'] : '';
    $error = auth_setup_finish($pdo, $code, $pw, $pw2);
    if ($error === '') {
        flash_set('ok', 'You are set up. From now on, sign in with your email, your new password and a 6-digit code.');
        header('Location: index.php');
        exit;
    }
}

$grouped = trim(chunk_split($setup['secret'], 4, ' '));
view_head('Set up your sign-in', false);
?>
<section class="card narrow">
    <h1>Set up your sign-in</h1>
    <p>Signed in as <strong><?= e($setup['email']) ?></strong>. Finish these steps within 15 minutes.</p>
    <?php if ($error !== ''): ?><p class="flash flash-error"><?= e($error) ?></p><?php endif; ?>
    <ol>
        <li>Open your authenticator app (Google Authenticator, Microsoft Authenticator or similar) and choose <strong>Add</strong>, then <strong>Enter a setup key</strong>.</li>
        <li>Account name: <strong>Licence panel</strong>. Key (type it exactly; spaces do not matter):
            <code class="keybox"><?= e($grouped) ?></code>
            Type: <strong>Time based</strong>.</li>
        <li>Type the 6-digit code the app now shows, and choose your own new password below.</li>
    </ol>
    <form method="post" action="setup.php" autocomplete="off">
        <?= csrf_field() ?>
        <label>6-digit code from the app
            <input type="text" name="code" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code"></label>
        <label>New password (at least <?= (int)AUTH_MIN_PASSWORD ?> characters)
            <input type="password" name="password" required minlength="<?= (int)AUTH_MIN_PASSWORD ?>" maxlength="1024" autocomplete="new-password"></label>
        <label>New password again
            <input type="password" name="password2" required minlength="<?= (int)AUTH_MIN_PASSWORD ?>" maxlength="1024" autocomplete="new-password"></label>
        <button type="submit">Finish and sign in</button>
    </form>
    <p class="muted">The key is shown only during this setup. Never share it or a screenshot of it: anyone with it can make your codes.</p>
</section>
<?php
view_foot();
