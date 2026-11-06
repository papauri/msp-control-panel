<?php
// Signing-key helpers shared by the Signing key page (public/keys.php) and scripts/make-keys.php.
// The private key lives at PRIVATE_KEY_PATH (outside public/), is created once and never overwritten,
// and never leaves the server through this app. The public key is derived from it when needed.

function keys_private_path(): string
{
    $p = (string)env('PRIVATE_KEY_PATH', '');
    return $p !== '' ? $p : APP_ROOT . '/keys/licence-private.pem';
}

/** Absolute, normalised directory path for prefix checks. */
function keys_norm_dir(string $p): string
{
    return strtolower(rtrim(str_replace('\\', '/', $p), '/')) . '/';
}

/** True when $path (existing or not) would sit inside public/. */
function keys_path_is_public(string $path): bool
{
    $publicDir = realpath(APP_ROOT . '/public');
    $probe = dirname($path);
    while (!is_dir($probe) && dirname($probe) !== $probe) {
        $probe = dirname($probe);
    }
    $realProbe = realpath($probe);
    if ($publicDir === false || $realProbe === false) {
        return true; // cannot prove it is safe
    }
    return strpos(keys_norm_dir($realProbe), keys_norm_dir($publicDir)) === 0;
}

/** The public key PEM derived from the private key, or null when there is no usable key. */
function keys_public_pem(?string $path = null): ?string
{
    $path = $path ?? keys_private_path();
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $k = openssl_pkey_get_private((string)file_get_contents($path));
    if ($k === false) {
        return null;
    }
    $d = openssl_pkey_get_details($k);
    return is_array($d) && !empty($d['key']) ? (string)$d['key'] : null;
}

/**
 * Creates the signing keypair at $path. Refuses to overwrite and refuses any path inside public/.
 * @return array{0:bool,1:string,2:?string} [ok, message, public PEM]
 */
function keys_create(?string $path = null): array
{
    $path = $path ?? keys_private_path();
    if (file_exists($path) || is_link($path)) {
        return [false, 'A signing key already exists. It is never replaced, so hotels keep trusting it.', keys_public_pem($path)];
    }
    // No ".." segments: the public-folder check below resolves the nearest existing ancestor, and a
    // "missing/../public/x" path would look safe there but land inside public/ after mkdir.
    if (in_array('..', preg_split('#[\\\\/]+#', $path), true)) {
        return [false, 'Refusing: PRIVATE_KEY_PATH must not contain "..". Use a plain absolute path.', null];
    }
    if (keys_path_is_public($path)) {
        return [false, 'Refusing: the key path must be outside the public folder. Check PRIVATE_KEY_PATH in .env.', null];
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return [false, 'Cannot create the key folder. Check that the folder in PRIVATE_KEY_PATH is writable.', null];
    }
    $pair = signing_generate_keypair();
    if ($pair === null) {
        return [false, 'The server could not generate a key (OpenSSL is unavailable).', null];
    }
    // Exclusive create (O_CREAT|O_EXCL): fails if anything, including a symlink, already exists at the
    // path, so two clicks at once can never replace a key and a planted link is never written through.
    $old = umask(0077);
    $fh = @fopen($path, 'x');
    umask($old);
    if ($fh === false) {
        return [false, file_exists($path) || is_link($path)
            ? 'A signing key already exists. It is never replaced, so hotels keep trusting it.'
            : 'Cannot write the key file. Check that the key folder is writable.', null];
    }
    $ok = fwrite($fh, $pair['private']) === strlen($pair['private']);
    fclose($fh);
    if (!$ok) {
        @unlink($path);
        return [false, 'Cannot write the key file. Check the free disk space and permissions.', null];
    }
    @chmod($path, 0600);
    // Belt and braces for Apache if the folder ever ends up under a web root.
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\n");
    }
    // The public key is not secret; saved beside it for convenience.
    @file_put_contents($dir . '/licence-public.pem', $pair['public']);
    return [true, 'Signing key created.', $pair['public']];
}
