<?php
// Billing overview (owner 2026-11-09): invoices, quotations and who owes what. Read-only list; changes happen on
// document.php (one invoice or quotation) and statement.php (one business). Contract: BILLING.md.
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);
$today = status_today();

$tab = isset($_GET['tab']) && in_array($_GET['tab'], ['invoices', 'quotes', 'owing'], true) ? (string)$_GET['tab'] : 'invoices';
$kind = $tab === 'quotes' ? 'quote' : 'invoice';
$statuses = billing_statuses($kind);
$fStatus = isset($_GET['status']) && is_string($_GET['status']) && (isset($statuses[$_GET['status']]) || $_GET['status'] === 'overdue') ? $_GET['status'] : '';
$fTenant = isset($_GET['tenant']) && ctype_digit((string)$_GET['tenant']) ? (int)$_GET['tenant'] : 0;
$pillClass = ['draft' => 'grace', 'sent' => 'read_only', 'paid' => 'active', 'void' => 'suspended', 'accepted' => 'active',
    'declined' => 'suspended', 'expired' => 'suspended', 'converted' => 'active'];

$tenants = $pdo->query('SELECT id, name FROM cp_tenants ORDER BY name')->fetchAll();
$owing = billing_owed_all($pdo);

// Totals per currency (owed and overdue on sent invoices).
$totals = [];
foreach ($owing as $o) {
    $c = $o['currency'];
    $totals[$c] = $totals[$c] ?? ['owed' => 0.0, 'overdue' => 0.0];
    $totals[$c]['owed'] = round($totals[$c]['owed'] + $o['owed'], 2);
    $totals[$c]['overdue'] = round($totals[$c]['overdue'] + $o['overdue'], 2);
}

view_head('Billing');
echo '<h1>Billing</h1>';
view_flash();

echo '<section class="card"><div class="bill-stats">';
if (!$totals) {
    echo '<p><span class="muted">Owed</span><strong>' . e(billing_money(0, billing_setting('default_currency'))) . '</strong></p>'
        . '<p><span class="muted">Overdue</span><strong>' . e(billing_money(0, billing_setting('default_currency'))) . '</strong></p>';
}
foreach ($totals as $c => $t) {
    echo '<p><span class="muted">Owed (' . e($c) . ')</span><strong>' . e(billing_money($t['owed'], $c)) . '</strong></p>'
        . '<p><span class="muted">Overdue (' . e($c) . ')</span><strong' . ($t['overdue'] > BILLING_TOLERANCE ? ' class="bill-overdue"' : '') . '>'
        . e(billing_money($t['overdue'], $c)) . '</strong></p>';
}
echo '</div><p class="bill-actions"><a class="button" href="document.php?new=invoice' . ($fTenant ? '&amp;tenant=' . $fTenant : '') . '">New invoice</a>'
    . '<a class="button secondary" href="document.php?new=quote' . ($fTenant ? '&amp;tenant=' . $fTenant : '') . '">New quotation</a></p>'
    . '<p class="muted">Owed counts sent invoices only; drafts and void invoices never count. Overdue means past the due date.</p></section>';

$tabUrl = static fn(string $t): string => 'invoices.php?tab=' . $t . ($fTenant ? '&amp;tenant=' . $fTenant : '');
echo '<nav class="tabs">';
foreach (['invoices' => 'Invoices', 'quotes' => 'Quotations', 'owing' => 'Owing'] as $k => $l) {
    echo '<a href="' . $tabUrl($k) . '"' . ($tab === $k ? ' class="active" aria-current="page"' : '') . '>' . e($l) . ($k === 'owing' && $owing ? ' (' . count($owing) . ')' : '') . '</a>';
}
echo '</nav>';

if ($tab === 'owing') {
    if (!$owing) {
        echo '<p class="muted">Nobody owes anything on sent invoices.</p>';
    } else {
        echo '<div class="scroll"><table class="list"><thead><tr>'
            . view_th('Business', 'The customer.') . view_th('Owed', 'Balance of all sent invoices.') . view_th('Overdue', 'Part of that past its due date.')
            . view_th('Oldest due', 'Due date of the oldest unpaid invoice.') . view_th('Billing email', 'Where invoices and reminders go.')
            . view_th('', 'Statement: everything invoiced and paid; send it or a payment request from there.', 'Statement')
            . '</tr></thead><tbody>';
        foreach ($owing as $o) {
            $late = $o['oldest_due'] !== null && $o['oldest_due'] < $today;
            echo '<tr><td data-label="Business" class="col-name"><a href="tenant.php?id=' . (int)$o['id'] . '">' . e($o['name']) . '</a></td>'
                . '<td data-label="Owed" class="nw num">' . e(billing_money($o['owed'], $o['currency'])) . '</td>'
                . '<td data-label="Overdue" class="nw num' . ($o['overdue'] > BILLING_TOLERANCE ? ' bill-overdue' : '') . '">' . e(billing_money($o['overdue'], $o['currency'])) . '</td>'
                . '<td data-label="Oldest due" class="nw">' . ($o['oldest_due'] ? e(billing_date($o['oldest_due'])) . ($late ? ' <span class="pill pill-suspended">Overdue</span>' : '') : view_empty()) . '</td>'
                . '<td data-label="Billing email">' . ($o['billing_email'] !== '' ? view_trunc($o['billing_email']) : '<span class="muted">none: add on the business page</span>') . '</td>'
                . '<td data-label="Statement"><a href="statement.php?tenant=' . (int)$o['id'] . '">Statement</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    view_foot();
    exit;
}

// Filters
echo '<form method="get" action="invoices.php" class="bill-filter"><input type="hidden" name="tab" value="' . e($tab) . '">'
    . '<label>Status<select name="status"><option value="">Any</option>';
foreach ($statuses as $k => $l) {
    echo '<option value="' . e($k) . '"' . ($fStatus === $k ? ' selected' : '') . '>' . e($l) . '</option>';
}
if ($kind === 'invoice') {
    echo '<option value="overdue"' . ($fStatus === 'overdue' ? ' selected' : '') . '>Overdue</option>';
}
echo '</select></label><label>Business<select name="tenant"><option value="">Any</option>';
foreach ($tenants as $t) {
    echo '<option value="' . (int)$t['id'] . '"' . ($fTenant === (int)$t['id'] ? ' selected' : '') . '>' . e($t['name']) . '</option>';
}
echo '</select></label><button type="submit" class="secondary">Show</button></form>';

$where = ['kind = ?'];
$args = [$kind];
if ($fStatus === 'overdue') {
    $where[] = "status = 'sent' AND due_date IS NOT NULL AND due_date < ? AND total - amount_paid > ?";
    $args[] = $today;
    $args[] = BILLING_TOLERANCE;
} elseif ($fStatus !== '') {
    $where[] = 'status = ?';
    $args[] = $fStatus;
}
if ($fTenant > 0) {
    $where[] = 'tenant_id = ?';
    $args[] = $fTenant;
}
$st = $pdo->prepare('SELECT * FROM cp_documents WHERE ' . implode(' AND ', $where) . ' ORDER BY issue_date DESC, id DESC LIMIT 300');
$st->execute($args);
$docs = $st->fetchAll();

if (!$docs) {
    echo '<p class="muted">No ' . ($kind === 'quote' ? 'quotations' : 'invoices') . ($fStatus !== '' || $fTenant ? ' match these filters' : ' yet') . '.</p>';
} else {
    echo '<div class="scroll"><table class="list"><thead><tr>'
        . view_th('No.', ($kind === 'quote' ? 'Quotation' : 'Invoice') . ' number.') . view_th('Customer', 'Business or prospect.')
        . view_th('Date', 'Date on the document.') . view_th($kind === 'quote' ? 'Valid until' : 'Due', $kind === 'quote' ? 'Quotation valid until.' : 'Due date.')
        . view_th('Total', 'Amount of the document.');
    if ($kind === 'invoice') {
        echo view_th('Balance', 'Still to pay.');
    }
    echo view_th('Status', 'Where it stands.') . '</tr></thead><tbody>';
    foreach ($docs as $d) {
        $d = billing_doc_decorate($d, $today);
        echo '<tr><td data-label="No." class="nw"><a href="document.php?id=' . (int)$d['id'] . '">' . e($d['number']) . '</a></td>'
            . '<td data-label="Customer" class="col-name">' . view_trunc($d['customer_name']) . ($d['tenant_id'] === null ? ' <span class="tag">prospect</span>' : '') . '</td>'
            . '<td data-label="Date" class="nw">' . e(billing_date($d['issue_date'])) . '</td>'
            . '<td data-label="' . ($kind === 'quote' ? 'Valid until' : 'Due') . '" class="nw">' . ($d['due_date'] ? e(billing_date($d['due_date'])) : view_empty()) . '</td>'
            . '<td data-label="Total" class="nw num">' . e(billing_money((float)$d['total'], $d['currency'])) . '</td>';
        if ($kind === 'invoice') {
            echo '<td data-label="Balance" class="nw num">' . (in_array($d['status'], ['void', 'draft'], true) ? view_empty() : e(billing_money($d['balance'], $d['currency']))) . '</td>';
        }
        echo '<td data-label="Status" class="nw"><span class="pill pill-' . e($pillClass[$d['status']] ?? 'grace') . '">' . e($statuses[$d['status']] ?? $d['status']) . '</span>'
            . ($d['is_overdue'] ? ' <span class="pill pill-suspended">Overdue</span>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div>';
    if (count($docs) === 300) {
        echo '<p class="muted">Showing the newest 300. Filter by business or status to see older ones.</p>';
    }
}
view_foot();
