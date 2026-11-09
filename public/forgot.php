<?php
// "Forgot your password?": asks for the email and always answers the same way (app/pwreset.php). The email itself is
// sent after this page has gone to the browser, so how long the page takes says nothing about whether the email exists.
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();

if (auth_current($pdo) !== null) {
    header('Location: index.php');
    exit;
}

$on = pwreset_available();
$asked = false;
$job = null;
if ($on && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $email = isset($_POST['email']) && is_string($_POST['email']) ? $_POST['email'] : '';
    try {
        $job = pwreset_request($pdo, $email);
    } catch (Throwable $e) {
        error_log('forgot: ' . get_class($e) . ': ' . $e->getMessage());
        $job = null;
    }
    $asked = true;
}

view_head('Forgot your password?', false);
echo '<section class="card narrow"><h1>Forgot your password?</h1>';
if (!$on) {
    echo '<p>Resetting by email is not switched on for this panel.</p>'
        . '<p>Ask another admin to reset you on the <strong>Users</strong> page. If you are the only admin, run '
        . '<code>php scripts/reset-password.php you@example.com</code> on the server (Guide, section 12 F).</p>';
} elseif ($asked) {
    echo '<p class="flash flash-info">If that email belongs to a panel user who has finished setting up, a reset link is on its way. '
        . 'It works for ' . (int)PWRESET_TTL_MINUTES . ' minutes. Check your spam folder too.</p>'
        . '<p class="muted">You will need your authenticator app as well: the link changes your password only. '
        . 'Lost your phone, or never finished setting up? Ask another admin to reset you on the <strong>Users</strong> page.</p>';
} else {
    echo '<p>Type the email you sign in with. We will send you a link to choose a new password.</p>'
        . '<form method="post" action="forgot.php" autocomplete="off">' . csrf_field()
        . '<label>Email<input type="email" name="email" required maxlength="190" autocomplete="username"></label>'
        . '<button type="submit">Send the link</button></form>'
        . '<p class="muted">You will still need a 6-digit code from your authenticator app to finish.</p>';
}
echo '<p><a href="login.php">Back to sign in</a></p></section>';
view_foot();

if ($job !== null) {
    backup_finish_response(); // lets the browser go first (where the server can); then send
    try {
        pwreset_send_link($pdo, $job);
    } catch (Throwable $e) {
        error_log('forgot: send: ' . get_class($e) . ': ' . $e->getMessage());
    }
} elseif ($asked && !backup_can_detach()) {
    usleep(random_int(700000, 1500000)); // the browser waits for the send above when it cannot be let go: wait alike here
}
