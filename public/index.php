<?php
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);
$today = status_today();

$rows = $pdo->query(
    'SELECT t.*,
        (SELECT c.checked_at FROM cp_licence_checks c WHERE c.tenant_id = t.id
           AND c.result_status IN (\'active\', \'grace\', \'read_only\', \'suspended\')
           ORDER BY c.checked_at DESC LIMIT 1) AS last_check,
        (SELECT c.pms_version FROM cp_licence_checks c WHERE c.tenant_id = t.id
           AND c.result_status IN (\'active\', \'grace\', \'read_only\', \'suspended\')
           ORDER BY c.checked_at DESC LIMIT 1) AS last_version
     FROM cp_tenants t ORDER BY t.name'
)->fetchAll();

view_head('Businesses');
view_flash();
echo '<h1>Businesses</h1>';
if (!$rows) {
    echo '<p>No businesses yet. <a href="tenant.php">Add the first business</a>.</p>';
} else {
    echo '<div class="scroll"><table class="list"><thead><tr>'
        . view_th('Business', 'The customer. Click to open, record a payment or change settings.', 'Open to manage')
        . view_th('Type', 'The kind of business (hotel, bar, shop and so on). Hover to see how many modules are licensed and which.', 'Kind (modules)')
        . view_th('Domain', 'The website address the customer system must report. A different domain is refused.', 'Address it must report')
        . view_th('Plan', 'The package the business pays for.', 'Package paid for')
        . view_th('Paid until', 'The last day covered by payments. After this date the step-down begins.', 'Last day covered')
        . view_th('Status', 'What the customer system is told today: active, grace (warning banner), read only, or suspended (admin locked). "override" means set by hand.', 'Told to customer today')
        . view_th('Last check-in', 'When the customer system last contacted this panel. A dash means it never has, or only failed attempts.', 'Last contact')
        . view_th('PMS version', 'The software version the business reported at its last check-in.', 'Reported at check-in')
        . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $st = status_compute($r, $today);
        $hasOverride = !empty($r['status_override']);
        $mods = catalog_tenant_modules($r);
        $names = [];
        foreach ($mods as $mk) {
            $names[] = catalog_modules()[$mk];
        }
        $modTitle = count($mods) . ' modules: ' . ($names ? implode(', ', $names) : 'none');
        echo '<tr><td data-label="Business" class="col-name"><a href="tenant.php?id=' . (int)$r['id'] . '">' . e($r['name']) . '</a></td>'
            . '<td data-label="Type" class="nw" title="' . e($modTitle) . '">' . e(catalog_type_label((string)($r['business_type'] ?? 'full_hotel')))
            . ' <span class="muted">(' . count($mods) . ')</span></td>'
            . '<td data-label="Domain" class="col-domain">' . ($r['domain'] !== null && $r['domain'] !== '' ? view_trunc($r['domain']) : view_empty()) . '</td>'
            . '<td data-label="Plan" class="col-plan">' . ($r['plan'] !== null && $r['plan'] !== '' ? view_trunc($r['plan']) : view_empty()) . '</td>'
            . '<td data-label="Paid until" class="nw">' . ($r['paid_until'] ? e($r['paid_until']) : view_empty()) . '</td>'
            . '<td data-label="Status" class="nw">' . view_pill($st, $hasOverride) . '</td>'
            . '<td data-label="Last check-in" class="nw">' . ($r['last_check'] ? e($r['last_check']) : view_empty()) . '</td>'
            . '<td data-label="PMS version" class="nw">' . ($r['last_version'] !== null && $r['last_version'] !== '' ? e($r['last_version']) : view_empty()) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
view_foot();
