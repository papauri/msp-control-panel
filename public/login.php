<?php
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();

if (auth_current($pdo) !== null) {
    header('Location: index.php');
    exit;
}

$failed = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $email = isset($_POST['email']) && is_string($_POST['email']) ? $_POST['email'] : '';
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $code = isset($_POST['code']) && is_string($_POST['code']) ? $_POST['code'] : '';
    $r = auth_attempt($pdo, $email, $password, $code);
    if ($r === 'setup') {
        header('Location: setup.php'); // first sign-in (or after a reset): set up the 6-digit codes and a new password
        exit;
    }
    if ($r === 'ok') {
        // The first sign-in of the day starts the daily backup (owner 2026-11-09), after the page has been sent
        $daily = false;
        try {
            $daily = backup_claim_today();
        } catch (Throwable $e) {
            error_log('daily backup claim: ' . $e->getMessage());
        }
        if ($daily) {
            flash_set('info', "Today's backup has started: the panel's own records, and every business has been asked to back itself up. See Backups.");
        }
        header('Location: index.php');
        if ($daily) {
            $who = (int)$_SESSION['admin_id'];
            backup_finish_response();
            try {
                $run = backup_run_all($pdo, 'first_sign_in', $who);
                backup_nudge_all($pdo, $run['asked']);
            } catch (Throwable $e) {
                error_log('daily backup: ' . $e->getMessage());
            }
        }
        exit;
    }
    $failed = true;
}

view_head('Sign in', false);
echo '<section class="card narrow"><h1>Sign in</h1>';
if ($failed) {
    echo '<p class="flash flash-error">Sign-in failed. Check your details and try again.</p>';
} elseif (isset($_GET['timeout'])) {
    echo '<p class="flash flash-info">You were signed out. Please sign in again.</p>';
}
echo '<form method="post" action="login.php" autocomplete="off">' . csrf_field()
    . '<label>Email<input type="email" name="email" required maxlength="190" autocomplete="username"></label>'
    . '<label>Password<input type="password" name="password" required maxlength="1024" autocomplete="current-password"></label>'
    . '<label>6-digit code from your authenticator app'
    . '<input type="text" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code"></label>'
    . '<p class="muted">First time signing in, or your account was reset? Leave the code empty: the next page sets up your codes.</p>'
    . '<button type="submit">Sign in</button></form></section>';
view_foot();
