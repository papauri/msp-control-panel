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
    echo '<div class="scroll"><table><thead><tr>'
        . view_th('Business', 'The customer. Click to open, record a payment or change settings.')
        . view_th('Type', 'The kind of business (hotel, bar, shop and so on). Hover to see how many modules are licensed and which.')
        . view_th('Domain', 'The website address the customer system must report. A different domain is refused.')
        . view_th('Plan', 'The package the business pays for.')
        . view_th('Paid until', 'The last day covered by payments. After this date the step-down begins.')
        . view_th('Status', 'What the customer system is told today: active, grace (warning banner), read only, or suspended (admin locked). "override" means set by hand.')
        . view_th('Last check-in', 'When the customer system last contacted this panel. Blank means it never has, or only failed attempts.')
        . view_th('PMS version', 'The software version the business reported at its last check-in.')
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
        echo '<tr><td data-label="Business"><a href="tenant.php?id=' . (int)$r['id'] . '">' . e($r['name']) . '</a></td>'
            . '<td data-label="Type" title="' . e($modTitle) . '">' . e(catalog_type_label((string)($r['business_type'] ?? 'full_hotel')))
            . ' <span class="muted">(' . count($mods) . ')</span></td>'
            . '<td data-label="Domain">' . e($r['domain']) . '</td>'
            . '<td data-label="Plan">' . e($r['plan']) . '</td>'
            . '<td data-label="Paid until">' . e($r['paid_until']) . '</td>'
            . '<td data-label="Status">' . view_pill($st, $hasOverride) . '</td>'
            . '<td data-label="Last check-in">' . ($r['last_check'] ? e($r['last_check']) : '<span class="muted">never</span>') . '</td>'
            . '<td data-label="PMS version">' . ($r['last_version'] !== null && $r['last_version'] !== '' ? e($r['last_version']) : '<span class="muted">-</span>') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
view_foot();
