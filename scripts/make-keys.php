<?php
// CLI only. Generates the ECDSA P-256 signing keypair.
//   php scripts/make-keys.php [private-key-path]
// Default path: <project>/keys/licence-private.pem (outside public/). Refuses to overwrite.
// Prints the PUBLIC key; paste it into the PMS as a code constant. The private key never leaves the server.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/signing.php';

$root = dirname(__DIR__);
$path = $argv[1] ?? ($root . '/keys/licence-private.pem');
if (file_exists($path)) {
    fwrite(STDERR, "Refusing to overwrite existing key at $path\n");
    exit(1);
}
$dir = dirname($path);
$publicDir = realpath($root . '/public');
// Resolve the nearest existing ancestor first so a not-yet-created public/sub/ cannot slip past.
$probe = $dir;
while (!is_dir($probe) && dirname($probe) !== $probe) {
    $probe = dirname($probe);
}
$realProbe = realpath($probe);
if ($publicDir === false || $realProbe === false) {
    fwrite(STDERR, "Cannot resolve the target path.\n");
    exit(1);
}
$norm = function ($p) {
    return strtolower(rtrim(str_replace('\\', '/', $p), '/')) . '/';
};
if (strpos($norm($realProbe), $norm($publicDir)) === 0) {
    fwrite(STDERR, "Refusing: the private key must be outside public/.\n");
    exit(1);
}
if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
    fwrite(STDERR, "Cannot create $dir\n");
    exit(1);
}

$pair = signing_generate_keypair();
if ($pair === null) {
    fwrite(STDERR, "OpenSSL could not generate a P-256 key. Is the openssl extension (and openssl.cnf) available?\n");
    exit(1);
}

$old = umask(0077);
$ok = file_put_contents($path, $pair['private']);
umask($old);
if ($ok === false) {
    fwrite(STDERR, "Cannot write $path\n");
    exit(1);
}
@chmod($path, 0600);

// Belt and braces for Apache hosts if the folder ever ends up under a web root.
$ht = $dir . '/.htaccess';
if (!file_exists($ht)) {
    @file_put_contents($ht, "Require all denied\n");
}

echo "Private key written to: $path (keep it secret; back it up offline)\n";
echo "Set PRIVATE_KEY_PATH in .env to this absolute path.\n\n";
echo "PUBLIC KEY (paste into the PMS as a code constant):\n\n";
echo $pair['public'];
