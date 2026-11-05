<?php
// ECDSA P-256 / SHA-256 via openssl. Signature is DER, carried as base64url.

function b64url_encode(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

// Strict: returns false on any character outside the url-safe alphabet.
function b64url_decode(string $s)
{
    if ($s === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $s) || strlen($s) % 4 === 1) {
        return false;
    }
    $pad = (4 - strlen($s) % 4) % 4;
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', $pad), true);
}

// Some builds (notably Windows PHP) ship without openssl.cnf, which breaks key generation.
// Fall back to a minimal temporary config in that case.
function signing_generate_keypair(): ?array
{
    $opts = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
    $tmpCfg = null;
    $key = @openssl_pkey_new($opts);
    if ($key === false) {
        $tmpCfg = tempnam(sys_get_temp_dir(), 'ossl');
        file_put_contents($tmpCfg, "[req]\ndefault_bits = 2048\ndistinguished_name = req_dn\n[req_dn]\n");
        $opts['config'] = $tmpCfg;
        $key = @openssl_pkey_new($opts);
    }
    if ($key === false) {
        if ($tmpCfg !== null) {
            @unlink($tmpCfg);
        }
        return null;
    }
    $exportOpts = $tmpCfg !== null ? ['config' => $tmpCfg] : null;
    $exported = @openssl_pkey_export($key, $priv, null, $exportOpts);
    if ($tmpCfg !== null) {
        @unlink($tmpCfg);
    }
    if (!$exported) {
        return null;
    }
    $d = openssl_pkey_get_details($key);
    if ($d === false || empty($d['key'])) {
        return null;
    }
    return ['private' => $priv, 'public' => $d['key']];
}

// Accepts a PEM string or a file path. Returns a key handle, or null when unusable.
function signing_load_private_key(string $pemOrPath)
{
    $pem = $pemOrPath;
    if (strpos($pemOrPath, '-----BEGIN') === false) {
        if ($pemOrPath === '' || !is_file($pemOrPath) || !is_readable($pemOrPath)) {
            return null;
        }
        $pem = file_get_contents($pemOrPath);
        if ($pem === false) {
            return null;
        }
    }
    $key = @openssl_pkey_get_private($pem);
    if ($key === false) {
        return null;
    }
    $d = openssl_pkey_get_details($key);
    if ($d === false || ($d['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($d['ec']['curve_name'] ?? '') !== 'prime256v1') {
        return null;
    }
    return $key;
}

function signing_sign(string $data, $privateKey): ?string
{
    $sig = '';
    if (!@openssl_sign($data, $sig, $privateKey, OPENSSL_ALGO_SHA256) || $sig === '') {
        return null;
    }
    return $sig;
}

function signing_verify(string $data, string $sigDer, string $publicPem): bool
{
    $pub = @openssl_pkey_get_public($publicPem);
    if ($pub === false) {
        return false;
    }
    return @openssl_verify($data, $sigDer, $pub, OPENSSL_ALGO_SHA256) === 1;
}

// Returns ['payload' => base64url(JSON), 'sig' => base64url(DER)] or null.
function signing_make_token(array $payload, $privateKey): ?array
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return null;
    }
    $sig = signing_sign($json, $privateKey);
    if ($sig === null) {
        return null;
    }
    return ['payload' => b64url_encode($json), 'sig' => b64url_encode($sig)];
}
