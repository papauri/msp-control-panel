<?php
// CLI only. Generates the ECDSA P-256 signing keypair (the Signing key page in the panel does the same).
//   php scripts/make-keys.php [private-key-path]
// Default path: PRIVATE_KEY_PATH from .env, else <project>/keys/licence-private.pem (outside public/).
// Refuses to overwrite. Prints the PUBLIC key; the private key never leaves the server.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

$path = $argv[1] ?? keys_private_path();
[$ok, $msg, $public] = keys_create($path);
if (!$ok) {
    fwrite(STDERR, $msg . "\n");
    exit(1);
}
echo "Private key written to: $path (keep it secret; back it up offline)\n";
echo "Set PRIVATE_KEY_PATH in .env to this absolute path if it differs.\n\n";
echo "PUBLIC KEY (paste into the PMS as a code constant):\n\n";
echo $public;
