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
