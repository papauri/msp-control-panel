<?php
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/signing.php';

$a = signing_generate_keypair();
$b = signing_generate_keypair();
if ($a === null || $b === null) {
    echo "FAIL: openssl cannot generate P-256 keys here\n";
    exit(1);
}
$key = signing_load_private_key($a['private']);
t_ok('private key loads from PEM', $key !== null);
t_eq('missing path -> null', null, signing_load_private_key('/nonexistent/key.pem'));
t_eq('garbage -> null', null, signing_load_private_key('not a key'));

$tmp = tempnam(sys_get_temp_dir(), 'key');
file_put_contents($tmp, $a['private']);
t_ok('private key loads from path', signing_load_private_key($tmp) !== null);
unlink($tmp);

// A non-P-256 EC key is rejected.
$cfg = tempnam(sys_get_temp_dir(), 'ossl');
file_put_contents($cfg, "[req]\ndefault_bits = 2048\ndistinguished_name = req_dn\n[req_dn]\n");
$k384 = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1', 'config' => $cfg]);
t_ok('P-384 test key generated', $k384 !== false);
if ($k384) {
    openssl_pkey_export($k384, $pem384, null, ['config' => $cfg]);
    t_eq('P-384 key rejected', null, signing_load_private_key($pem384));
}
unlink($cfg);

$payload = ['v' => 1, 'tenant' => 'liwonde', 'domain' => 'hotel.example.com', 'status' => 'active',
    'paid_until' => '2026-12-31', 'grace_days' => 14, 'readonly_days' => 14, 'issued_at' => 1000, 'valid_until' => 2000];
$tok = signing_make_token($payload, $key);
t_ok('token produced', $tok !== null && isset($tok['payload'], $tok['sig']));
$json = b64url_decode($tok['payload']);
$sig = b64url_decode($tok['sig']);
t_ok('payload decodes to the same JSON', $json !== false && json_decode($json, true) === $payload);
t_ok('sig is a DER sequence', $sig !== false && ord($sig[0]) === 0x30);
t_ok('payload and sig are url-safe', preg_match('/^[A-Za-z0-9_-]+$/', $tok['payload'] . $tok['sig']) === 1);
t_ok('valid signature verifies', signing_verify($json, $sig, $a['public']));
t_ok('tampered payload fails', !signing_verify(str_replace('active', 'actiVe', $json), $sig, $a['public']));
t_ok('appended byte fails', !signing_verify($json . ' ', $sig, $a['public']));
$bad = $sig;
$bad[strlen($bad) - 1] = chr(ord($bad[strlen($bad) - 1]) ^ 1);
t_ok('tampered signature fails', !signing_verify($json, $bad, $a['public']));
t_ok('truncated signature fails', !signing_verify($json, substr($sig, 0, -3), $a['public']));
t_ok('empty signature fails', !signing_verify($json, '', $a['public']));
t_ok('wrong key fails', !signing_verify($json, $sig, $b['public']));
t_ok('garbage public key fails closed', !signing_verify($json, $sig, 'not a pem'));
$s2 = signing_sign($json, $key);
t_ok('re-signing gives a different but valid signature', $s2 !== null && $s2 !== $sig && signing_verify($json, $s2, $a['public']));

// base64url strictness.
t_eq('b64 round trip', "\xfb\xff\xfe", b64url_decode(b64url_encode("\xfb\xff\xfe")));
t_eq('b64 rejects +', false, b64url_decode('ab+c'));
t_eq('b64 rejects padding', false, b64url_decode('abcd='));
t_eq('b64 rejects empty', false, b64url_decode(''));
t_eq('b64 rejects length%4==1', false, b64url_decode('abcde'));
t_done('signing_test');
