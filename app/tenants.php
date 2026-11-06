<?php
// Tenant helpers shared by the dashboard, tenant page and licence endpoint.

function tenant_find(PDO $pdo, int $id, bool $forUpdate = false): ?array
{
    $st = $pdo->prepare('SELECT * FROM cp_tenants WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

function tenant_valid_date(string $d): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
        return false;
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && (int)$m[1] >= 2000 && (int)$m[1] <= 2100;
}

// 256-bit random key. Returned once to the owner; only a hash is stored.
function tenant_new_api_key(): string
{
    return 'mspk_' . bin2hex(random_bytes(32));
}

// Last 4 characters of a licence key, shown so the owner can match keys. Useless on its own.
function tenant_key_hint(string $key): string
{
    return strlen($key) >= 12 ? substr($key, -4) : '';
}

// Returns [array $errors, array $clean]. $isNew allows setting the slug.
function tenant_validate(array $in, bool $isNew): array
{
    $err = [];
    $s = function ($k, $max) use ($in) {
        $v = isset($in[$k]) && is_string($in[$k]) ? trim($in[$k]) : '';
        return mb_substr($v, 0, $max);
    };
    $clean = [
        'slug' => strtolower($s('slug', 64)),
        'name' => $s('name', 190),
        'domain' => licence_normalise_domain($s('domain', 255)),
        'plan' => $s('plan', 60),
        'currency' => strtoupper($s('currency', 3)),
        'paid_until' => $s('paid_until', 10),
        'notes' => $s('notes', 2000),
        'business_type' => $s('business_type', 40),
    ];
    if (!isset(catalog_business_types()[$clean['business_type']])) {
        $err[] = 'Choose a business type from the list.';
        $clean['business_type'] = 'custom';
    }
    $clean['modules'] = catalog_clean_modules($in['modules'] ?? []);
    // A preset type must match its preset exactly (the Assistant add-on may be added). Otherwise it is Custom.
    if ($clean['business_type'] !== 'custom' && !catalog_matches_preset($clean['business_type'], $clean['modules'])) {
        $clean['business_type'] = 'custom';
    }
    $clean['modules_json'] = json_encode($clean['modules']);
    if ($isNew && !preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $clean['slug'])) {
        $err[] = 'Short name must be 2-63 characters: lowercase letters, numbers and hyphens.';
    }
    if ($clean['name'] === '') {
        $err[] = 'Business name is required.';
    }
    if (!licence_domain_valid($clean['domain'])) {
        $err[] = 'Enter the business website domain, for example shop.example.com.';
    }
    if (!preg_match('/^[A-Z]{3}$/', $clean['currency'])) {
        $err[] = 'Currency must be a 3-letter code such as MWK.';
    }
    if (!tenant_valid_date($clean['paid_until'])) {
        $err[] = 'Paid until must be a valid date (YYYY-MM-DD).';
    }
    $fee = isset($in['monthly_fee']) && is_string($in['monthly_fee']) ? trim($in['monthly_fee']) : '';
    if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $fee)) {
        $err[] = 'Monthly fee must be a number, for example 150000 or 150000.50.';
    }
    $clean['monthly_fee'] = $fee === '' ? '0' : $fee;
    foreach (['grace_days' => 'Grace days', 'readonly_days' => 'Read-only days'] as $k => $label) {
        $v = isset($in[$k]) && is_string($in[$k]) ? trim($in[$k]) : '';
        if (!preg_match('/^\d{1,3}$/', $v) || (int)$v > 365) {
            $err[] = $label . ' must be a whole number from 0 to 365.';
            $clean[$k] = 0;
        } else {
            $clean[$k] = (int)$v;
        }
    }
    return [$err, $clean];
}

/**
 * Nudge a business's system to check in now (POST https://<domain>/api/licence-ping.php). The nudge carries nothing:
 * the business still fetches and verifies the signed answer itself, so a change made here reaches it within seconds
 * instead of waiting for its hourly check. Best effort: a short timeout, the result is ignored.
 */
function tenant_nudge(PDO $pdo, int $tenantId): void
{
    try {
        $t = tenant_find($pdo, $tenantId);
        $domain = $t ? licence_normalise_domain((string)$t['domain']) : '';
        if ($domain === '' || !licence_domain_valid($domain) || !function_exists('curl_init')) {
            return;
        }
        $ch = curl_init('https://' . $domain . '/api/licence-ping.php');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (Throwable $e) {
        error_log('tenant_nudge: ' . $e->getMessage());
    }
}

function flash_set(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function flash_take(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}
