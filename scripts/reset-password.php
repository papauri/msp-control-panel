<?php
// CLI only. The way back in when an admin has forgotten their password and no other admin can reset them on the
// Users page. Needs server access (cPanel Terminal or SSH), never the web.
//   php scripts/reset-password.php owner@example.com
// Asks for a new password (min 12 characters). The 6-digit codes are kept. Signs the account out everywhere.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

$email = strtolower(trim($argv[1] ?? ''));
if (!users_valid_email($email)) {
    fwrite(STDERR, "Usage: php scripts/reset-password.php email@example.com\n");
    exit(1);
}
$pdo = db();
$st = $pdo->prepare('SELECT id FROM cp_admins WHERE email = ?');
$st->execute([$email]);
$id = (int)$st->fetchColumn();
if ($id === 0) {
    fwrite(STDERR, "No admin with that email. Nothing was changed.\n");
    exit(1);
}

function prompt_hidden(string $label): string
{
    echo $label;
    $stty = (stripos(PHP_OS, 'WIN') !== 0) && function_exists('shell_exec');
    if ($stty) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($stty) {
        shell_exec('stty echo');
        echo "\n";
    }
    return $line === false ? '' : rtrim($line, "\r\n");
}

$pw = prompt_hidden('New password (min ' . AUTH_MIN_PASSWORD . ' characters): ');
$pw2 = prompt_hidden('Repeat it: ');
if (($p = auth_password_problem($pw, $pw2, $email)) !== '') {
    fwrite(STDERR, $p . " Nothing was changed.\n");
    exit(1);
}
$pdo->prepare('UPDATE cp_admins SET password_hash = ?, failed_count = 0, locked_until = NULL WHERE id = ?')->execute([auth_hash_password($pw), $id]);
audit_log($pdo, $id, 'password_reset_cli', null, null, ['email' => $email]);
echo "Password changed for $email. Sign in with it and your usual 6-digit code.\n";
