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
        // Only where the page can be sent first: otherwise the cron job (or a sign-in on a server that can) runs it,
        // and today is not claimed so nothing is skipped.
        $day = null;
        if (backup_can_detach()) {
            try {
                $day = backup_claim_today();
            } catch (Throwable $e) {
                error_log('daily backup claim: ' . $e->getMessage());
            }
        }
        if ($day !== null) {
            flash_set('info', "Today's backup has started: the panel's own records, and every business has been asked to back itself up. See Backups.");
        }
        header('Location: index.php');
        if ($day !== null) {
            $who = (int)$_SESSION['admin_id'];
            if (!backup_finish_response()) {
                backup_release_day($day); // the browser would wait: give today back for the cron job or the next sign-in
                exit;
            }
            try {
                $run = backup_run_all($pdo, 'first_sign_in', $who, $day); // gives today back itself when it fails
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
    . '<button type="submit">Sign in</button></form>'
    . (pwreset_available() ? '<p><a href="forgot.php">Forgot your password?</a></p>' : '')
    . '</section>';
view_foot();
