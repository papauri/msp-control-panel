<?php
// CLI only. The way back in when an admin cannot get a 6-digit code to work: a lost phone, or the signing key that
// encrypts the 2-step secrets is lost and there is no backup. Needs server access (cPanel Terminal or SSH), never the web.
//   php scripts/reset-2fa.php owner@example.com
// Gives the admin a NEW 2-step secret (the old authenticator entry stops working) and clears old lock fields.
// The password is not changed. Prints the secret and otpauth:// URI once, for manual entry in the authenticator app.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

$email = strtolower(trim($argv[1] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    fwrite(STDERR, "Usage: php scripts/reset-2fa.php email@example.com\n");
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

$secret = totp_generate_secret();
$boxKey = keys_secret_box_key();
$stored = $boxKey !== null ? (totp_seal($secret, $boxKey) ?? $secret) : $secret;
$pdo->prepare('UPDATE cp_admins SET totp_secret = ?, totp_last_step = NULL, failed_count = 0, locked_until = NULL WHERE id = ?')
    ->execute([$stored, $id]);
audit_log($pdo, $id, 'admin_2fa_reset', null, null, ['email' => $email, 'encrypted' => $stored !== $secret]);

echo "\nNew 2-step secret for $email\n";
echo "TOTP secret (enter manually in your authenticator app): $secret\n";
echo "otpauth URI: " . totp_uri($secret, $email, 'Licence panel') . "\n";
echo ($stored !== $secret ? "Stored encrypted with the signing key.\n" : "No signing key found: stored plain for now; it is encrypted at the next sign-in once the key is back.\n");
echo "Remove the old entry from the app. Store these now; the secret is not shown again.\n";
