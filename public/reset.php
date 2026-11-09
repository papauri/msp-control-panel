<?php
// The page a "Forgot your password?" email links to (app/pwreset.php). The link's secret is moved out of the address
// bar into the session on arrival, so it is not left in the browser history. Finishing needs a new password AND a
// current 6-digit code; then the person is signed in and every other session of the account ends.
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();

if (isset($_GET['t']) && is_string($_GET['t'])) {
    $t = strtolower(trim($_GET['t']));
    $_SESSION['pwreset_token'] = pwreset_token_ok($t) ? $t : '';
    // Not a Location redirect: arriving from an email is a cross-site visit, and the browser would also hold back the
    // SameSite=Strict session cookie on a redirect that follows it. A refresh from this page is a same-site visit.
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">'
        . '<meta http-equiv="refresh" content="0;url=reset.php"><title>Choose a new password - Licence panel</title>'
        . '<link rel="stylesheet" href="assets/app.css"></head><body><main><section class="card narrow">'
        . '<p><a class="button" href="reset.php">Continue</a></p></section></main></body></html>';
    exit;
}
if (auth_current($pdo) !== null) {
    unset($_SESSION['pwreset_token']);
    header('Location: index.php');
    exit;
}

$token = is_string($_SESSION['pwreset_token'] ?? null) ? $_SESSION['pwreset_token'] : '';
$error = '';
$expired = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $code = isset($_POST['code']) && is_string($_POST['code']) ? $_POST['code'] : '';
    $pw = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $pw2 = isset($_POST['password2']) && is_string($_POST['password2']) ? $_POST['password2'] : '';
    $r = pwreset_finish($pdo, $token, $code, $pw, $pw2);
    if ($r['error'] === '') {
        unset($_SESSION['pwreset_token']);
        flash_set('ok', 'Your password has been changed and you are signed in. Other browsers signed in as you have been signed out.');
        header('Location: index.php');
        backup_finish_response();
        try {
            pwreset_send_notice($pdo, $r['notice']);
        } catch (Throwable $e) {
            error_log('reset: notice: ' . get_class($e) . ': ' . $e->getMessage());
        }
        exit;
    }
    $error = $r['error'];
    $expired = $r['expired'];
}

$row = $expired ? null : pwreset_lookup($pdo, $token);
view_head('Choose a new password', false);
echo '<section class="card narrow"><h1>Choose a new password</h1>';
if ($row === null) {
    unset($_SESSION['pwreset_token']);
    echo '<p class="flash flash-error">This link has expired or has already been used.</p>'
        . '<p>Links work once, for ' . (int)PWRESET_TTL_MINUTES . ' minutes, and only the newest one you asked for works.</p>'
        . '<p><a class="button" href="forgot.php">Ask for a new link</a></p>';
} else {
    if ($error !== '') {
        echo '<p class="flash flash-error">' . e($error) . '</p>';
    }
    echo '<p>For <strong>' . e((string)$row['email']) . '</strong>.</p>'
        . '<form method="post" action="reset.php" autocomplete="off">' . csrf_field()
        . '<label>New password (at least ' . (int)AUTH_MIN_PASSWORD . ' characters)'
        . '<input type="password" name="password" required minlength="' . (int)AUTH_MIN_PASSWORD . '" maxlength="1024" autocomplete="new-password"></label>'
        . '<label>New password again'
        . '<input type="password" name="password2" required minlength="' . (int)AUTH_MIN_PASSWORD . '" maxlength="1024" autocomplete="new-password"></label>'
        . '<label>6-digit code from your authenticator app'
        . '<input type="text" name="code" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code"></label>'
        . '<button type="submit">Change password and sign in</button></form>'
        . '<p class="muted">Lost your phone? This link cannot help: ask another admin to reset you on the <strong>Users</strong> page.</p>';
}
echo '<p><a href="login.php">Back to sign in</a></p></section>';
view_foot();
