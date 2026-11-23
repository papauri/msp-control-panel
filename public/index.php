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

// Search, status tabs and pages (owner 2026-11-24). Filtering happens here, after status_compute, so a tab always
// matches the pill shown. Search looks in the name, domain and plan.
$g = static fn(string $k): string => isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : '';
$q = mb_substr($g('q'), 0, 100);
$tabs = ['all' => 'All', 'active' => 'Active', 'grace' => 'Grace', 'read_only' => 'Read only', 'suspended' => 'Suspended'];
$tab = isset($tabs[$g('status')]) ? $g('status') : 'all';
$perOpts = ['10' => 10, '25' => 25, '50' => 50, 'all' => 0];
$perIn = isset($perOpts[$g('per')]) ? $g('per') : '10';
$per = $perOpts[$perIn];
$counts = array_fill_keys(array_keys($tabs), 0);
$shown = [];
foreach ($rows as $r) {
    if ($q !== '') {
        $hay = mb_strtolower((string)$r['name'] . ' ' . (string)($r['domain'] ?? '') . ' ' . (string)($r['plan'] ?? ''));
        if (mb_strpos($hay, mb_strtolower($q)) === false) {
            continue;
        }
    }
    $r['_st'] = status_compute($r, $today);
    $counts['all']++;
    if (isset($counts[$r['_st']])) {
        $counts[$r['_st']]++;
    }
    if ($tab === 'all' || $r['_st'] === $tab) {
        $shown[] = $r;
    }
}
$total = count($shown);
$pages = $per > 0 ? max(1, (int)ceil($total / $per)) : 1;
$page = ctype_digit($g('page')) ? min(max(1, (int)$g('page')), $pages) : 1;
$pageRows = $per > 0 ? array_slice($shown, ($page - 1) * $per, $per) : $shown;
$link = static function (array $set) use ($q, $tab, $perIn): string {
    $p = array_merge(['q' => $q, 'status' => $tab, 'per' => $perIn, 'page' => null], $set);
    if ($p['status'] === 'all') {
        $p['status'] = null; // the defaults stay out of the address
    }
    if ($p['per'] === '10') {
        $p['per'] = null;
    }
    $p = array_filter($p, static fn($v): bool => $v !== null && $v !== '');
    return 'index.php' . ($p ? '?' . http_build_query($p) : '');
};

view_head('Businesses');
echo view_page_head('Businesses', 'Every customer system that checks in with this panel, and what each one is told today.',
    '<a class="button" href="tenant.php">Add business</a>');
view_flash();
if ($rows) {
    echo '<nav class="tabs">';
    foreach ($tabs as $k => $w) {
        echo '<a href="' . e($link(['status' => $k])) . '"' . ($tab === $k ? ' class="active" aria-current="page"' : '') . '>'
            . e($w) . ' (' . (int)$counts[$k] . ')</a>';
    }
    echo '</nav>'
        . '<form method="get" action="index.php" class="inline">'
        . ($tab !== 'all' ? '<input type="hidden" name="status" value="' . e($tab) . '">' : '')
        . '<label>Search <input type="search" name="q" value="' . e($q) . '" maxlength="100" placeholder="Name, domain or plan"></label>'
        . '<label>Show <select name="per">';
    foreach (['10' => '10', '25' => '25', '50' => '50', 'all' => 'All'] as $k => $w) {
        echo '<option value="' . e($k) . '"' . ($perIn === $k ? ' selected' : '') . '>' . e($w) . '</option>';
    }
    echo '</select></label> <button type="submit" class="secondary">Apply</button>'
        . ($q !== '' ? ' <a href="' . e($link(['q' => null])) . '">Clear search</a>' : '')
        . '</form>';
}
if (!$rows) {
    echo '<p>No businesses yet. <a href="tenant.php">Add the first business</a>.</p>';
} elseif (!$shown) {
    echo '<p class="muted">No business matches' . ($q !== '' ? ' "' . e($q) . '"' : '') . ($tab !== 'all' ? ' in ' . e($tabs[$tab]) : '')
        . '. <a href="index.php">Show all</a>.</p>';
} else {
    echo '<div class="scroll"><table class="list"><thead><tr>'
        . view_th('Business', 'The customer. Click to open, record a payment or change settings.', 'Open to manage')
        . view_th('Type', 'The kind of business (hotel, bar, shop and so on). Hover to see how many modules are licensed and which.', 'Kind (modules)')
        . view_th('Domain', 'The website address the customer system must report. A different domain is refused.', 'Address it must report')
        . view_th('Plan', 'The package the business pays for.', 'Package paid for')
        . view_th('Paid until', 'The last day covered by payments. After this date the step-down begins.', 'Last day covered')
        . view_th('Status', 'What the customer system is told today: active, grace (warning banner), read only, or suspended (admin locked). "override" means set by hand. A free trial shows the days left, or "Trial ended".', 'Told to customer today')
        . view_th('Last check-in', 'When the customer system last contacted this panel. A dash means it never has, or only failed attempts.', 'Last contact')
        . view_th('PMS version', 'The software version the business reported at its last check-in.', 'Reported at check-in')
        . '</tr></thead><tbody>';
    foreach ($pageRows as $r) {
        $st = $r['_st'];
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
            . '<td data-label="Status" class="nw">' . view_pill($st, $hasOverride) . (trial_words($r, $today) !== '' ? ' ' . view_trial_tag(trial_words($r, $today)) : '') . '</td>'
            . '<td data-label="Last check-in" class="nw">' . ($r['last_check'] ? e($r['last_check']) : view_empty()) . '</td>'
            . '<td data-label="PMS version" class="nw">' . ($r['last_version'] !== null && $r['last_version'] !== '' ? e($r['last_version']) : view_empty()) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    if ($pages > 1) {
        echo '<p class="pager">'
            . ($page > 1 ? '<a href="' . e($link(['page' => $page - 1])) . '">Previous page</a> ' : '')
            . '<span class="muted">Page ' . (int)$page . ' of ' . (int)$pages . ' (' . (int)$total . ' businesses)</span>'
            . ($page < $pages ? ' <a href="' . e($link(['page' => $page + 1])) . '">Next page</a>' : '')
            . '</p>';
    }
}
view_foot();
