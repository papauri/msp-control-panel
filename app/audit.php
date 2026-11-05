<?php
// Every state change is written here with before/after. Secrets are stripped.

function audit_strip(?array $row): ?array
{
    if ($row === null) {
        return null;
    }
    foreach (['api_key_hash', 'password_hash', 'totp_secret'] as $k) {
        unset($row[$k]);
    }
    return $row;
}

function audit_log(PDO $pdo, ?int $adminId, string $action, ?int $tenantId, ?array $before, ?array $after): void
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;
    $b = $before === null ? null : json_encode(audit_strip($before), $flags);
    $a = $after === null ? null : json_encode(audit_strip($after), $flags);
    $st = $pdo->prepare(
        'INSERT INTO audit_log (admin_id, action, tenant_id, before_json, after_json, ip)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$adminId, substr($action, 0, 60), $tenantId, $b, $a, client_ip()]);
}
