<?php
// 2026-11-09 security fixes, no database: 2-step secret sealed at rest, per-address sign-in lockout bucket,
// the one-time licence key dropped from the session outside its page.
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/bootstrap.php'; // functions only: nothing here connects to the database

$a = signing_generate_keypair();
$b = signing_generate_keypair();
if ($a === null || $b === null) {
    echo "FAIL: openssl cannot generate P-256 keys here\n";
    exit(1);
}

// ── Secret at rest ──
$kA = keys_secret_box_key($a['private']);
$kB = keys_secret_box_key($b['private']);
t_ok('box key is 32 bytes', is_string($kA) && strlen($kA) === 32);
t_ok('different signing keys give different box keys', $kA !== $kB);
$crlf = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $a['private']));
t_ok('same key with CRLF line endings (a restored backup) gives the same box key', keys_secret_box_key($crlf) === $kA);
$tmp = tempnam(sys_get_temp_dir(), 'key');
file_put_contents($tmp, $crlf);
t_ok('same box key when the key is read from a file', keys_secret_box_key($tmp) === $kA);
unlink($tmp);
t_eq('no signing key -> no box key', null, keys_secret_box_key('/nonexistent/key.pem'));

$s = totp_generate_secret();
$sealed = totp_seal($s, $kA);
t_ok('sealed secret fits the VARCHAR(64) column exactly', is_string($sealed) && strlen($sealed) === 64);
t_ok('sealed secret is recognised as sealed', totp_secret_is_sealed((string)$sealed));
t_ok('plain secret is not taken for a sealed one', !totp_secret_is_sealed($s));
t_eq('seal then open gives the same secret', $s, totp_open((string)$sealed, $kA));
t_ok('two seals of the same secret differ (fresh nonce)', totp_seal($s, $kA) !== $sealed);
t_eq('wrong key cannot open it', null, totp_open((string)$sealed, $kB));
t_eq('no key cannot open it', null, totp_open((string)$sealed, null));
$bin = base64_decode((string)$sealed);
$bin[20] = chr(ord($bin[20]) ^ 1);
t_eq('a changed value is refused', null, totp_open(base64_encode($bin), $kA));
t_eq('an older plain secret still opens (no key needed)', $s, totp_open($s, null));
t_eq('lower-case plain secret still opens', strtolower($s), totp_open(strtolower($s), $kA));
t_eq('a bad secret is never sealed', null, totp_seal('NOT*BASE32', $kA));
t_eq('a short key is never used', null, totp_seal($s, 'short'));
$now = 1700000000;
$code = totp_code((string)base32_decode_str($s), intdiv($now, 30));
t_ok('a code from the opened secret verifies', totp_verify((string)totp_open((string)$sealed, $kA), $code, $now) !== false);
t_eq('store value: plain + key -> sealed (keeps working)', $s, totp_open(totp_seal($s, $kA) ?? '', $kA));
// The sign-in write path, with whatever signing key this machine has (none locally; the real one on the server)
$here = keys_secret_box_key();
$stored = auth_totp_store_value($s, $s);
if ($here === null) {
    t_eq('sign-in without a signing key keeps the plain secret as it is', $s, $stored);
} else {
    t_ok('sign-in with the signing key stores a sealed secret that opens to the same one', totp_secret_is_sealed($stored) && totp_open($stored, $here) === $s);
}
t_eq('sign-in never re-writes an already sealed value', $sealed, auth_totp_store_value((string)$sealed, $s));
t_eq('a plain secret reads as it is at sign-in', $s, auth_totp_secret($s));
$logWas = ini_set('error_log', sys_get_temp_dir() . '/cp-security-test.log');
t_eq('a sealed secret with no signing key is refused (logged, never a wrong code)', null, auth_totp_secret((string)$sealed));
ini_set('error_log', (string)$logWas);

// ── Per-address lockout bucket ──
$b1 = auth_fail_bucket('owner@example.com', '203.0.113.5');
t_ok('bucket fits the 100-character column', strlen($b1) <= 100);
t_ok('another address has its own count (the owner is never locked out from elsewhere)', $b1 !== auth_fail_bucket('owner@example.com', '198.51.100.7'));
t_ok('another email has its own count', $b1 !== auth_fail_bucket('other@example.com', '203.0.113.5'));
$long = str_repeat('x', 180) . '@example.com';
t_ok('long emails and IPv6 addresses are never cut into the same bucket',
    auth_fail_bucket($long, '2001:db8::1') !== auth_fail_bucket($long, '2001:db8::2') && strlen(auth_fail_bucket($long, '2001:db8::1')) <= 100);
t_ok('bucket does not contain the email', strpos($b1, 'owner') === false);

// ── One-time licence key ──
$run = static function (array $nk, string $script, string $method, ?string $id): bool {
    $_SESSION = ['new_key' => $nk];
    $_SERVER['SCRIPT_NAME'] = '/' . $script;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_GET = $id === null ? [] : ['id' => $id];
    new_key_purge();
    return isset($_SESSION['new_key']);
};
$t = time();
t_ok('kept for the redirect to its own business page', $run([7, 'k', $t], 'tenant.php', 'GET', '7'));
t_ok('dropped on another business page', !$run([7, 'k', $t], 'tenant.php', 'GET', '8'));
t_ok('dropped on any other page', !$run([7, 'k', $t], 'index.php', 'GET', '7'));
t_ok('dropped on the login page', !$run([7, 'k', $t], 'login.php', 'GET', null));
t_ok('dropped on a POST', !$run([7, 'k', $t], 'tenant.php', 'POST', '7'));
t_ok('dropped after 2 minutes', !$run([7, 'k', $t - 121], 'tenant.php', 'GET', '7'));
t_ok('dropped when in the old 2-part form', !$run([7, 'k'], 'tenant.php', 'GET', '7'));
$_SESSION = [];
new_key_purge();
t_ok('nothing to do without a key', $_SESSION === []);

t_done('security');
