<?php
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/totp.php';

// RFC 6238 Appendix B, SHA-1, secret "12345678901234567890", 8 digits.
$secret = '12345678901234567890';
$vectors = [
    59 => '94287082',
    1111111109 => '07081804',
    1111111111 => '14050471',
    1234567890 => '89005924',
    2000000000 => '69279037',
    20000000000 => '65353130',
];
foreach ($vectors as $time => $code) {
    t_eq("RFC6238 8-digit T=$time", $code, totp_code($secret, intdiv($time, 30), 8));
    t_eq("RFC6238 6-digit T=$time", substr($code, 2), totp_code($secret, intdiv($time, 30), 6));
}

// Base32 (RFC 4648 vectors) and round trip.
t_eq('b32 foobar', 'MZXW6YTBOI', base32_encode_str('foobar'));
t_eq('b32 decode', 'foobar', base32_decode_str('MZXW6YTBOI'));
t_eq('b32 decode lowercase/spaces', 'foobar', base32_decode_str('mzxw 6ytb oi'));
t_eq('b32 bad char', false, base32_decode_str('MZXW1YTB'));
$s = totp_generate_secret();
t_ok('generated secret is base32 of 20 bytes', preg_match('/^[A-Z2-7]{32}$/', $s) === 1 && strlen(base32_decode_str($s)) === 20);

// Verification window of +/- 1 step.
$b32 = base32_encode_str($secret);
$now = 1111111109;
$step = intdiv($now, 30);
t_eq('verify current step', $step, totp_verify($b32, totp_code($secret, $step), $now));
t_eq('verify previous step', $step - 1, totp_verify($b32, totp_code($secret, $step - 1), $now));
t_eq('verify next step', $step + 1, totp_verify($b32, totp_code($secret, $step + 1), $now));
t_eq('reject two steps old', false, totp_verify($b32, totp_code($secret, $step - 2), $now));
t_eq('reject two steps ahead', false, totp_verify($b32, totp_code($secret, $step + 2), $now));
$c = totp_code($secret, $step);
t_eq('accept spaced code', $step, totp_verify($b32, substr($c, 0, 3) . ' ' . substr($c, 3), $now));
t_eq('reject non-numeric', false, totp_verify($b32, 'abcdef', $now));
t_eq('reject short', false, totp_verify($b32, '12345', $now));
t_eq('reject empty', false, totp_verify($b32, '', $now));
t_eq('reject bad secret', false, totp_verify('!!!', '123456', $now));
t_eq('reject empty secret', false, totp_verify('', '123456', $now));
t_ok('uri shape', strpos(totp_uri('ABC', 'a@b.com', 'Licence panel'), 'otpauth://totp/Licence%20panel%3Aa%40b.com?secret=ABC&issuer=Licence%20panel') === 0);
t_done('totp_test');
