<?php
// RFC 6238 TOTP (HMAC-SHA1, 30 s step, 6 digits by default) and RFC 4648 base32.

function base32_encode_str(string $bin): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $ch) {
        $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alpha[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

function base32_decode_str(string $b32)
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(str_replace([' ', '-', '='], '', $b32));
    if ($b32 === '') {
        return false;
    }
    $bits = '';
    foreach (str_split($b32) as $ch) {
        $p = strpos($alpha, $ch);
        if ($p === false) {
            return false;
        }
        $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totp_generate_secret(): string
{
    return base32_encode_str(random_bytes(20));
}

function totp_code(string $secretBin, int $counter, int $digits = 6): string
{
    $msg = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
    $h = hash_hmac('sha1', $msg, $secretBin, true);
    $o = ord($h[19]) & 0x0F;
    $bin = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

// Returns the matched time-step (int) or false. Window is +/- $window steps.
function totp_verify(string $secretB32, string $code, int $now, int $window = 1, int $period = 30, int $digits = 6)
{
    $secret = base32_decode_str($secretB32);
    $code = (string)preg_replace('/\s+/', '', $code);
    if ($secret === false || $secret === '' || !preg_match('/^\d{' . $digits . '}$/', $code)) {
        return false;
    }
    $step = intdiv($now, $period);
    $matched = false;
    for ($i = -$window; $i <= $window; $i++) {
        $c = $step + $i;
        if ($c >= 0 && hash_equals(totp_code($secret, $c, $digits), $code)) {
            $matched = $c; // no early exit: constant work
        }
    }
    return $matched;
}

function totp_uri(string $secretB32, string $account, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
        . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

// ── Secret at rest (2026-11-09) ──────────────────────────────────────────────
// cp_admins.totp_secret is VARCHAR(64). A sealed secret is base64(nonce 12 | AES-256-GCM ciphertext of the 20 raw
// secret bytes | tag 16) = 48 bytes = exactly 64 characters. A plain (older) secret is 32 base32 characters, so the two
// are told apart by length and alphabet; no prefix fits and the column is not widened (schema changes are the owner's).

const TOTP_SEALED_LEN = 64;

function totp_secret_is_sealed(string $stored): bool
{
    return strlen($stored) === TOTP_SEALED_LEN && preg_match('#^[A-Za-z0-9+/]{64}$#', $stored) === 1;
}

/** Seals a base32 secret with a 32-byte key. Null unless the result fits the column and opens back to the same secret. */
function totp_seal(string $secretB32, string $key): ?string
{
    $raw = base32_decode_str($secretB32);
    if ($raw === false || strlen($raw) !== 20 || strlen($key) !== 32) {
        return null;
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($raw, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false || strlen($tag) !== 16) {
        return null;
    }
    $sealed = base64_encode($nonce . $ct . $tag);
    if (strlen($sealed) !== TOTP_SEALED_LEN || totp_open($sealed, $key) !== base32_encode_str($raw)) {
        return null; // never store anything that does not open again: a bad value would lock the admin out
    }
    return $sealed;
}

/**
 * The base32 secret from what is stored: a plain (older) secret is returned as it is; a sealed one is opened with
 * $key. Null when it is sealed and the key is missing, wrong, or the value was changed.
 */
function totp_open(string $stored, ?string $key): ?string
{
    if (!totp_secret_is_sealed($stored)) {
        return $stored;
    }
    if ($key === null || strlen($key) !== 32) {
        return null;
    }
    $bin = base64_decode($stored, true);
    if ($bin === false || strlen($bin) !== 48) {
        return null;
    }
    $raw = openssl_decrypt(substr($bin, 12, 20), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 32, 16));
    return ($raw === false || strlen($raw) !== 20) ? null : base32_encode_str($raw);
}
