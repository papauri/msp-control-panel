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
    if (auth_attempt($pdo, $email, $password, $code)) {
        header('Location: index.php');
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
    . '<input type="text" name="code" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code"></label>'
    . '<button type="submit">Sign in</button></form></section>';
view_foot();
