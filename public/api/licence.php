<?php
// Licence check-in endpoint. POST JSON only, no cookies, no sessions.
// Request:  {"tenant": "<slug>", "domain": "<site host>", "key": "<api key>", "version": "<pms version>"}
// Response: {"payload": base64url(JSON), "sig": base64url(DER ECDSA P-256/SHA-256 over the JSON bytes)}

require_once __DIR__ . '/../../app/bootstrap.php';

const LICENCE_VALID_SECONDS = 35 * 86400;

function licence_reply(int $code, array $body): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body);
    exit;
}

function licence_denied(): void
{
    licence_reply(403, ['error' => 'denied']);
}

function licence_log(PDO $pdo, ?int $tenantId, string $domain, string $version, string $result): void
{
    try {
        $st = $pdo->prepare(
            'INSERT INTO cp_licence_checks (tenant_id, ip, domain_reported, pms_version, result_status) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$tenantId, client_ip(), substr($domain, 0, 255), substr($version, 0, 40), substr($result, 0, 20)]);
    } catch (Throwable $e) {
        error_log('licence_log: ' . $e->getMessage());
    }
}

set_exception_handler(function ($e) {
    error_log('licence exception: ' . get_class($e) . ': ' . $e->getMessage());
    licence_reply(500, ['error' => 'unavailable']);
});

header('Content-Type: application/json; charset=utf-8');

// The API key must never travel in clear: HTTPS only (loopback allowed for local testing).
if (!is_https() && !in_array(client_ip(), ['127.0.0.1', '::1'], true)) {
    licence_reply(403, ['error' => 'https_required']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    licence_reply(405, ['error' => 'method']);
}
$ctype = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (strpos($ctype, 'application/json') !== 0) {
    licence_reply(415, ['error' => 'content_type']);
}
$raw = file_get_contents('php://input', false, null, 0, 4097);
if ($raw === false || $raw === '' || strlen($raw) > 4096) {
    licence_reply(400, ['error' => 'bad_request']);
}
$in = json_decode($raw, true);
if (!is_array($in)) {
    licence_reply(400, ['error' => 'bad_request']);
}
$slug = isset($in['tenant']) && is_string($in['tenant']) ? $in['tenant'] : '';
$domainIn = isset($in['domain']) && is_string($in['domain']) ? $in['domain'] : '';
$keyIn = isset($in['key']) && is_string($in['key']) ? $in['key'] : '';
$version = isset($in['version']) && is_string($in['version']) ? $in['version'] : '';
if ($slug === '' || strlen($slug) > 64 || $domainIn === '' || strlen($domainIn) > 255 || $keyIn === '' || strlen($keyIn) > 200 || strlen($version) > 40) {
    licence_reply(400, ['error' => 'bad_request']);
}
$version = preg_replace('/[^\x20-\x7E]/', '', $version);

// Signing key must be usable before anything else; otherwise never sign.
$privateKey = signing_load_private_key((string)env('PRIVATE_KEY_PATH', ''));
if ($privateKey === null) {
    error_log('licence: private key missing or unreadable');
    licence_reply(503, ['error' => 'unavailable']);
}

$pdo = db();

// Per-IP limit applies to every request (valid or not), so it cannot reveal which tenants exist.
if (!rate_limit_hit($pdo, 'lic-ip:' . client_ip(), 30, 3600)) {
    licence_reply(429, ['error' => 'rate_limited']);
}

$st = $pdo->prepare('SELECT * FROM cp_tenants WHERE slug = ?');
$st->execute([strtolower($slug)]);
$tenant = $st->fetch();

// Always spend one password_verify so timing is the same for unknown tenant / no key / wrong key.
$hash = ($tenant && !empty($tenant['api_key_hash'])) ? $tenant['api_key_hash'] : AUTH_DUMMY_HASH;
$keyOk = password_verify($keyIn, $hash) && $tenant && !empty($tenant['api_key_hash']);
$domainOk = $tenant
    && hash_equals(licence_normalise_domain((string)$tenant['domain']), licence_normalise_domain($domainIn));

if (!$keyOk || !$domainOk) {
    licence_log($pdo, $tenant ? (int)$tenant['id'] : null, $domainIn, $version, $keyOk ? 'domain_mismatch' : 'denied');
    licence_denied();
}

// Authenticated from here on, so a tenant-specific limit is safe to disclose and cannot be
// burned by strangers guessing keys.
if (!rate_limit_hit($pdo, 'lic-t:' . sha1((string)$tenant['id']), 30, 3600)) {
    licence_reply(429, ['error' => 'rate_limited']);
}

$status = status_compute($tenant, status_today());
$now = time();
$payload = [
    'v' => 1,
    'tenant' => $tenant['slug'],
    'domain' => licence_normalise_domain((string)$tenant['domain']),
    'status' => $status,
    'override' => !empty($tenant['status_override']),
    'paid_until' => $tenant['paid_until'],
    'grace_days' => (int)$tenant['grace_days'],
    'readonly_days' => (int)$tenant['readonly_days'],
    'issued_at' => $now,
    'valid_until' => $now + LICENCE_VALID_SECONDS,
];
$token = signing_make_token($payload, $privateKey);
if ($token === null) {
    error_log('licence: signing failed');
    licence_reply(503, ['error' => 'unavailable']);
}
licence_log($pdo, (int)$tenant['id'], $domainIn, $version, $status);
licence_reply(200, $token);
