<?php
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/signing.php';
require __DIR__ . '/../../app/domain.php';
require __DIR__ . '/../../app/status.php';
require __DIR__ . '/../../client/licence-verify.php';

$kp = signing_generate_keypair();
$other = signing_generate_keypair();
if ($kp === null || $other === null) {
    echo "FAIL: openssl cannot generate P-256 keys here\n";
    exit(1);
}
$priv = signing_load_private_key($kp['private']);
$pub = $kp['public'];

$NOW = gmmktime(12, 0, 0, 6, 1, 2026);
$DAY = 86400;

function mk(array $over, $priv): array
{
    global $NOW, $DAY;
    $p = array_merge([
        'v' => 1, 'tenant' => 'liwonde', 'domain' => 'hotel.example.com', 'status' => 'active', 'override' => false,
        'paid_until' => '2026-06-30', 'grace_days' => 14, 'readonly_days' => 14,
        'issued_at' => $NOW, 'valid_until' => $NOW + 35 * $DAY,
    ], $over);
    return signing_make_token($p, $priv);
}
function ev($tok, int $now, int $last = 0, string $domain = 'hotel.example.com', string $tenant = 'liwonde', string $fb = 'active'): array
{
    global $pub;
    return lv_evaluate($tok, $pub, $tenant, $domain, $last, $now, $fb);
}
function at(string $d): int
{
    return gmmktime(12, 0, 0, (int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
}

// Happy path.
$tok = mk([], $priv);
$r = ev($tok, $NOW);
t_eq('valid -> active', 'active', $r['status']);
t_eq('source server', 'server', $r['source']);
t_eq('issued_at returned', $NOW, $r['issued_at']);
t_eq('JSON string token accepted', 'active', ev(json_encode($tok), $NOW)['status']);

// Business type and modules ride in the signed payload (additive; v stays 1).
$r = ev(mk(['business_type' => 'retail_shop', 'modules' => ['pos', 'stock', 'finance']], $priv), $NOW);
t_eq('business_type returned', 'retail_shop', $r['business_type']);
t_eq('modules returned', ['pos', 'stock', 'finance'], $r['modules']);
t_eq('modules also in payload', ['pos', 'stock', 'finance'], $r['payload']['modules']);
t_eq('modules do not change status', 'active', $r['status']);
$r = ev(mk(['business_type' => 'custom', 'modules' => []], $priv), $NOW);
t_eq('empty modules list stays empty', [], $r['modules']);
$r = ev($tok, $NOW);
t_eq('older token: business_type null', null, $r['business_type']);
t_eq('older token: modules null', null, $r['modules']);
$r = ev(mk(['business_type' => 'x', 'modules' => ['pos', 'pos', 5, 'Bad Key', 'gym']], $priv), $NOW);
t_eq('modules sanitised on read', ['pos', 'gym'], $r['modules']);
$r = ev($tok, $NOW, 0, 'evil.example.org');
t_eq('fallback carries no modules', null, $r['modules']);

// Domain and tenant binding.
t_eq('www. and case treated as same', 'ok', ev($tok, $NOW, 0, 'WWW.Hotel.Example.com')['reason']);
t_eq('scheme/port stripped', 'ok', ev($tok, $NOW, 0, 'https://hotel.example.com:443/')['reason']);
$r = ev($tok, $NOW, 0, 'evil.example.org');
t_eq('domain mismatch reason', 'domain_mismatch', $r['reason']);
t_eq('domain mismatch uses fallback status', 'active', $r['status']);
t_eq('domain mismatch source', 'fallback', $r['source']);
t_eq('fallback is caller-defined', 'suspended', ev($tok, $NOW, 0, 'evil.example.org', 'liwonde', 'suspended')['status']);
t_eq('tenant mismatch', 'tenant_mismatch', ev($tok, $NOW, 0, 'hotel.example.com', 'rosalyns')['reason']);

// Wrong key and tampering.
t_eq('wrong public key', 'bad_signature', lv_evaluate($tok, $other['public'], 'liwonde', 'hotel.example.com', 0, $NOW)['reason']);
$t2 = $tok;
$t2['payload'] = b64url_encode(str_replace('"active"', '"suspd"', b64url_decode($t2['payload'])));
t_eq('tampered payload', 'bad_signature', ev($t2, $NOW)['reason']);
$t3 = $tok;
$s = b64url_decode($t3['sig']);
$s[10] = chr(ord($s[10]) ^ 0xff);
$t3['sig'] = b64url_encode($s);
t_eq('tampered sig', 'bad_signature', ev($t3, $NOW)['reason']);
t_eq('bad public key string', 'bad_public_key', lv_evaluate($tok, 'garbage', 'liwonde', 'hotel.example.com', 0, $NOW)['reason']);

// Validly signed but malformed payloads are rejected.
foreach ([
    'v=2' => ['v' => 2], 'status invalid' => ['status' => 'vip'], 'bad date' => ['paid_until' => '2026-13-45'],
    'grace as string' => ['grace_days' => '14'], 'valid_until<=issued' => ['valid_until' => $NOW],
    'negative grace' => ['grace_days' => -1],
] as $name => $over) {
    t_eq("bad payload: $name", 'bad_payload', ev(mk($over, $priv), $NOW)['reason']);
}
// A clock set back must not reject a genuine token, and must not loosen the status.
$fut = mk(['issued_at' => $NOW + 5 * $DAY, 'valid_until' => $NOW + 40 * $DAY, 'paid_until' => '2026-05-01'], $priv);
$r = ev($fut, $NOW);
t_eq('future-issued token still accepted', 'ok', $r['reason']);
t_eq('clock rollback does not loosen status', 'suspended', $r['status']);
$rolled = ev($tok, at('2026-01-01'), $NOW);
t_eq('rolled-back clock cannot reach earlier schedule', 'ok', $rolled['reason']);
t_eq('rolled-back clock: status computed from last seen time', 'active', $rolled['status']);
$rolled2 = ev($lateTok = mk(['paid_until' => '2026-05-01'], $priv), at('2026-01-01'), $NOW);
t_eq('rolled-back clock still steps down', 'suspended', $rolled2['status']);

// Anti-replay: older issued_at than the last accepted is refused; equal is fine.
$old = mk(['issued_at' => $NOW - 10 * $DAY, 'valid_until' => $NOW + 25 * $DAY], $priv);
t_eq('replay of older token', 'replayed', ev($old, $NOW, $NOW)['reason']);
t_eq('replay -> fallback source', 'fallback', ev($old, $NOW, $NOW)['source']);
t_eq('same issued_at re-evaluates ok', 'ok', ev($tok, $NOW, $NOW)['reason']);
t_eq('newer than last ok', 'ok', ev($tok, $NOW, $NOW - 1)['reason']);

// Server status vs local schedule.
$susp = mk(['status' => 'suspended', 'override' => true], $priv);
t_eq('server override suspended honoured', 'suspended', ev($susp, $NOW)['status']);
t_eq('override source', 'server_override', ev($susp, $NOW)['source']);
$forced = mk(['status' => 'active', 'override' => true, 'paid_until' => '2026-01-01'], $priv);
t_eq('forced active override beats elapsed date', 'active', ev($forced, $NOW)['status']);
t_eq('forced active override ends with token validity', 'suspended', ev($forced, $NOW + 36 * $DAY)['status']);
// A forced restriction survives token expiry even when the date schedule would say active.
$lockedAhead = mk(['status' => 'suspended', 'override' => true, 'paid_until' => '2027-12-31'], $priv);
t_eq('suspended override survives token expiry', 'suspended', ev($lockedAhead, $NOW + 36 * $DAY)['status']);
$roAhead = mk(['status' => 'read_only', 'override' => true, 'paid_until' => '2027-12-31'], $priv);
t_eq('read_only override survives token expiry', 'read_only', ev($roAhead, $NOW + 36 * $DAY)['status']);
$lenient = mk(['status' => 'active', 'paid_until' => '2026-05-01'], $priv); // by 06-01 the schedule says suspended
t_eq('stricter of server vs local schedule', 'suspended', ev($lenient, $NOW)['status']);

// Offline step-down. paid_until 2026-06-30 -> grace to 07-14 -> read-only to 07-28.
t_eq('offline: on paid_until still active', 'active', ev($tok, at('2026-06-30'))['status']);
t_eq('offline: day after -> grace', 'grace', ev($tok, at('2026-07-01'))['status']);
t_eq('offline: end of grace', 'grace', ev($tok, at('2026-07-14'))['status']);
t_eq('offline: read_only', 'read_only', ev($tok, at('2026-07-15'))['status']);
// Token expires 2026-07-06; later dates exercise the post-expiry schedule path.
$r = ev($tok, at('2026-07-20'));
t_eq('expired token: schedule used', 'read_only', $r['status']);
t_eq('expired token: source schedule', 'schedule', $r['source']);
t_eq('expired token: suspended after read-only', 'suspended', ev($tok, at('2026-07-29'))['status']);
t_eq('expired token long after', 'suspended', ev($tok, at('2027-01-01'))['status']);

// Garbage never throws and falls back.
$garbage = [null, '', [], 'not json', '{}', '[]', 123, ['payload' => 1, 'sig' => 2], ['payload' => 'a', 'sig' => 'b'],
    ['payload' => '!!!', 'sig' => '???'], str_repeat('x', 100000), ['payload' => str_repeat('A', 5000), 'sig' => 'AAAA'],
    json_encode(['payload' => b64url_encode('{"v":1}'), 'sig' => b64url_encode('zz')])];
foreach ($garbage as $i => $g) {
    try {
        $r = ev($g, $NOW);
        t_eq("garbage #$i falls back", 'fallback', $r['source']);
        t_eq("garbage #$i status = fallback", 'active', $r['status']);
        t_eq("garbage #$i has no issued_at", null, $r['issued_at']);
    } catch (Throwable $e) {
        t_ok("garbage #$i must not throw: " . $e->getMessage(), false);
    }
}
try {
    $r = lv_evaluate($tok, '', '', '', 0, 0, 'active');
    t_ok('empty site config does not throw', is_array($r));
} catch (Throwable $e) {
    t_ok('empty site config does not throw', false);
}
t_eq('unconfigured fallback = active', 'active', ev(null, $NOW)['status']);

// Server and verifier agree.
foreach (['Hotel.Example.com', 'https://www.a.b.com:8080/x', 'a.com.', ' WWW.a.com ', 'u:p@a.com'] as $d) {
    t_eq("normalisers agree: $d", licence_normalise_domain($d), lv_normalise_domain($d));
}
foreach (['2026-06-30', '2026-07-01', '2026-07-14', '2026-07-15', '2026-07-28', '2026-07-29'] as $d) {
    t_eq("schedules agree on $d", status_schedule('2026-06-30', 14, 14, $d), lv_schedule_status('2026-06-30', 14, 14, $d));
}
t_done('verifier_test');
