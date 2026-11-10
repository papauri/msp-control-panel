<?php
// HMS staff report (owner 2026-11-10). POST JSON only, no cookies, no sessions. Same key and domain check as api/licence.php.
// Request: {"tenant", "domain", "key",
//           "results": [{"id": int, "ok": bool, "message": str}]           what happened to each change the panel sent,
//           "staff": [{"id", "username", "email", "full_name", "role", "is_active", "last_login", "grants": [], "denies": []}],
//           "catalog": {"roles": [{"key", "label", "level", "defaults": [..]|null}], "permissions": [{"key", "label", "category"}]}}
//           "ai": {"ready", "enabled", "off_reason", "provider", "key_last4", "key_status", "models": [{"model_id", "role"}], "budget_usd", "usage": {...}}
// "staff", "catalog" and "ai" may be left out when only results are sent. Never a password, never an AI key.
// Response: {"ok": true, "acked": [command ids recorded]}. Nothing in the answer is an instruction: instructions only ever
// travel in the SIGNED licence answer.

require_once __DIR__ . '/../../app/bootstrap.php';

function hms_reply(int $code, array $body): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body);
    exit;
}

set_exception_handler(function ($e) {
    error_log('hms-staff exception: ' . get_class($e) . ': ' . $e->getMessage());
    hms_reply(500, ['error' => 'unavailable']);
});

if (!is_https() && !in_array(client_ip(), ['127.0.0.1', '::1'], true)) {
    hms_reply(403, ['error' => 'https_required']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    hms_reply(405, ['error' => 'method']);
}
if (strpos(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json') !== 0) {
    hms_reply(415, ['error' => 'content_type']);
}
$raw = file_get_contents('php://input', false, null, 0, HMS_REPORT_MAX_BYTES + 1);
if ($raw === false || $raw === '' || strlen($raw) > HMS_REPORT_MAX_BYTES) {
    hms_reply(400, ['error' => 'bad_request']);
}
$in = json_decode($raw, true);
if (!is_array($in)) {
    hms_reply(400, ['error' => 'bad_request']);
}

$pdo = db();
if (!rate_limit_hit($pdo, 'hms-ip:' . client_ip(), 30, 3600)) {
    hms_reply(429, ['error' => 'rate_limited']);
}
$tenant = hms_authenticate($pdo, $in);
if ($tenant === null) {
    hms_reply(403, ['error' => 'denied']);
}
if (!rate_limit_hit($pdo, 'hms-t:' . sha1((string)$tenant['id']), 30, 3600)) {
    hms_reply(429, ['error' => 'rate_limited']);
}

$results = hms_clean_results($in['results'] ?? []);
$staff = array_key_exists('staff', $in) && is_array($in['staff']) ? hms_clean_staff($in['staff']) : null;
$catalog = array_key_exists('catalog', $in) ? hms_clean_catalog($in['catalog']) : null;
$acked = hms_store_report($pdo, (int)$tenant['id'], $results, $staff, $catalog);
// The hotel's Assistant (2026-11-11, app/ai.php): provider, last 4 of the key, models, on/off, this month's usage. Never a key.
$aiState = array_key_exists('ai', $in) ? ai_clean_state($in['ai']) : null;
if ($aiState !== null) {
    ai_store_state($pdo, (int)$tenant['id'], $aiState);
}
if ($results) {
    audit_log($pdo, null, 'hms_changes_confirmed', (int)$tenant['id'], null, ['results' => array_map(
        static fn($r) => $r['id'] . ':' . ($r['ok'] ? 'ok' : 'failed'), $results)]);
}
hms_reply(200, ['ok' => true, 'acked' => $acked]);
