<?php
// CLI only. There is no web sign-up.
//   php scripts/create-admin.php owner@example.com
// Prompts for a password (min 12 chars), stores its hash, prints the TOTP secret and otpauth:// URI
// for manual entry into an authenticator app. The secret is shown once, here.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

$email = strtolower(trim($argv[1] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    fwrite(STDERR, "Usage: php scripts/create-admin.php email@example.com\n");
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

$pw = prompt_hidden('Password (min 12 characters): ');
$pw2 = prompt_hidden('Repeat password: ');
if ($pw !== $pw2 || strlen($pw) < 12) {
    fwrite(STDERR, "Passwords differ or are shorter than 12 characters.\n");
    exit(1);
}

$pdo = db();
$secret = totp_generate_secret();
try {
    $st = $pdo->prepare('INSERT INTO cp_admins (email, password_hash, totp_secret) VALUES (?, ?, ?)');
    $st->execute([$email, password_hash($pw, PASSWORD_BCRYPT, ['cost' => 10]), $secret]);
} catch (PDOException $e) {
    fwrite(STDERR, ($e->getCode() === '23000' ? "That email already exists.\n" : "Database error; nothing was created.\n"));
    exit(1);
}
$id = (int)$pdo->lastInsertId();
audit_log($pdo, $id, 'admin_created', null, null, ['email' => $email]);

echo "\nAdmin created: $email\n";
echo "TOTP secret (enter manually in your authenticator app): $secret\n";
echo "otpauth URI: " . totp_uri($secret, $email, 'Licence panel') . "\n";
echo "Store these now; the secret is not shown again.\n";
