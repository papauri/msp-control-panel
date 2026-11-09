<?php
// Clients (owner 2026-11-09, BILLING.md Part 2): everyone we bill, companies and individuals, hosted or not.
// Search and filters; what each owes per currency. Read-only list; changes happen on client.php.
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);

$g = static fn(string $k): string => isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : '';
$q = mb_substr($g('q'), 0, 100);
$f = ['kind' => isset(client_kinds()[$g('kind')]) ? $g('kind') : '', 'hosted' => in_array($g('hosted'), ['0', '1'], true) ? $g('hosted') : '',
    'active' => in_array($g('active'), ['0', '1', 'any'], true) ? $g('active') : '1'];
$rows = client_search($pdo, $q, ['kind' => $f['kind'], 'hosted' => $f['hosted'], 'active' => $f['active'] === 'any' ? '' : $f['active']]);

view_head('Clients');
echo '<h1>Clients</h1>';
view_flash();
echo '<p class="bill-actions"><a class="button" href="client.php?new=1">Add client</a></p>';
$sel = static fn(string $cur, string $val): string => $cur === $val ? ' selected' : '';
echo '<form method="get" action="clients.php" class="bill-filter">'
    . '<label>Search<input type="text" name="q" maxlength="100" value="' . e($q) . '" placeholder="Name, contact, email or phone"></label>'
    . '<label>Kind<select name="kind"><option value="">Any</option>';
foreach (client_kinds() as $k => $l) {
    echo '<option value="' . e($k) . '"' . $sel($f['kind'], $k) . '>' . e($l) . '</option>';
}
echo '</select></label><label>Hosted<select name="hosted"><option value="">Any</option><option value="1"' . $sel($f['hosted'], '1') . '>Hosted business</option>'
    . '<option value="0"' . $sel($f['hosted'], '0') . '>Not hosted</option></select></label>'
    . '<label>Status<select name="active"><option value="1"' . $sel($f['active'], '1') . '>Active</option><option value="0"' . $sel($f['active'], '0') . '>Inactive</option>'
    . '<option value="any"' . $sel($f['active'], 'any') . '>Any</option></select></label>'
    . '<button type="submit" class="secondary">Show</button></form>';

if (!$rows) {
    echo '<p class="muted">' . ($q !== '' || $f['kind'] !== '' || $f['hosted'] !== '' ? 'No clients match.' : 'No clients yet. Add one, or add a business: it gets its client automatically.') . '</p>';
} else {
    echo '<div class="scroll"><table class="list"><thead><tr>' . view_th('Client', 'Company or person.') . view_th('Kind', 'Company or individual.')
        . view_th('Contact', 'Contact person.') . view_th('Email', 'Where invoices and receipts go.') . view_th('Phone', 'Phone number.')
        . view_th('Hosted', 'Has a licence on this panel.') . view_th('Owed', 'Balance of sent invoices, per currency.') . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $owed = [];
        foreach ($r['owed'] as $c => $o) {
            $owed[] = '<span class="nw' . ($o['overdue'] > BILLING_TOLERANCE ? ' bill-overdue' : '') . '">' . e(billing_money($o['owed'], $c)) . '</span>';
        }
        echo '<tr><td data-label="Client" class="col-name"><a href="client.php?id=' . (int)$r['id'] . '">' . e($r['name']) . '</a>'
            . ((int)$r['is_active'] !== 1 ? ' <span class="tag">inactive</span>' : '') . '</td>'
            . '<td data-label="Kind">' . e(client_kinds()[$r['kind']] ?? $r['kind']) . '</td>'
            . '<td data-label="Contact">' . ($r['contact_name'] ? view_trunc($r['contact_name']) : view_empty()) . '</td>'
            . '<td data-label="Email">' . ($r['email'] ? view_trunc($r['email']) : view_empty()) . '</td>'
            . '<td data-label="Phone" class="nw">' . ($r['phone'] ? e($r['phone']) : view_empty()) . '</td>'
            . '<td data-label="Hosted">' . ($r['tenant_id'] !== null ? '<a href="tenant.php?id=' . (int)$r['tenant_id'] . '">Yes</a>' : 'No') . '</td>'
            . '<td data-label="Owed" class="num">' . ($owed ? implode('<br>', $owed) : view_empty()) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    if (count($rows) >= 300) {
        echo '<p class="muted">Showing the first 300. Search to narrow the list.</p>';
    }
}
view_foot();
