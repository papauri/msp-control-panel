<?php
// Reports (owner 2026-11-09, BILLING.md Part 2, agent REPORTS): what was invoiced, collected, owed and earned in a
// period, aged debt, sales by month / client / category / product, payments; every table exports to Excel, and
// "Export everything" gives one workbook. Read-only: GET only, SELECT only. Logic in app/reports.php, Excel in app/xlsx.php.
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/xlsx.php';
require_once __DIR__ . '/../app/reports.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);
$today = status_today();

$g = static fn(string $k): string => isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : '';
$period = reports_period($g('period'), $g('from'), $g('to'), $today);
$data = reports_load($pdo, $period, $today);
$exports = reports_exports();

// Excel downloads (signed-in only: auth_require above).
$export = $g('export');
if ($export !== '') {
    $keys = $export === 'all' ? reports_everything() : (isset($exports[$export]) ? [$export] : []);
    if (!$keys) {
        http_response_code(400);
        echo 'Unknown export.';
        exit;
    }
    @set_time_limit(120);
    $extra = [];
    if (in_array('quotes', $keys, true)) {
        $extra['quotes'] = reports_quotes($pdo, $period['from'], $period['to']);
    }
    if (in_array('clients', $keys, true)) {
        $extra['clients'] = reports_clients($pdo);
    }
    $sheets = [];
    foreach ($keys as $k) {
        $sheets[] = reports_sheet($k, $data, $extra);
    }
    // client data leaving the panel is recorded (data protection): who, what, which period
    audit_log($pdo, (int)$admin['id'], 'report_exported', null, null, ['export' => $export, 'from' => $period['from'], 'to' => $period['to']]);
    xlsx_stream(xlsx_build($sheets), reports_filename($export === 'all' ? 'Sales report' : $exports[$export], $period));
    exit;
}

$q = static fn(array $more = []): string => e('reports.php?' . http_build_query(array_merge(
    ['period' => $period['key']], $period['key'] === 'custom' ? ['from' => $period['from'], 'to' => $period['to']] : [], $more)));
$money = static fn($amount, string $cur): string => e(billing_money((float)$amount, $cur));
$qty = static fn($v): string => e(rtrim(rtrim(number_format((float)$v, 2, '.', ','), '0'), '.'));
$clientCell = static function (array $r, string $nameKey = 'client'): string {
    $name = (string)($r[$nameKey] ?? '');
    $id = (int)($r['client_id'] ?? $r['client_ref'] ?? 0);
    $label = $name !== '' ? view_trunc($name) : view_empty();
    return $id > 0 ? '<a href="client.php?id=' . $id . '">' . $label . '</a>' : $label;
};
$profitCell = static function (array $r, string $cur) use ($money): string {
    if (empty($r['known'])) {
        return '<span class="muted" title="None of these lines has a cost price, so profit is not known.">not known</span>';
    }
    return $money($r['profit'], $cur) . (!empty($r['partial'])
        ? ' <span class="tag rep-partial" title="' . (int)$r['lines_without_cost'] . ' line(s) have no cost price and are left out of the profit.">partial</span>' : '');
};
$head = static function (string $title, string $exportKey, string $help) use ($q): void {
    echo '<div class="rep-head"><h2>' . e($title) . '</h2>'
        . '<a class="button secondary" href="' . $q(['export' => $exportKey]) . '">Export to Excel</a></div>'
        . '<p class="muted rep-help">' . e($help) . '</p>';
};

view_head('Reports');
echo '<h1>Reports</h1>';
view_flash();
?>
<section class="card">
    <form method="get" action="reports.php" class="rep-period">
        <label>Period
            <select name="period">
                <?php foreach (REPORTS_PERIODS as $k => $l): ?>
                    <option value="<?= e($k) ?>"<?= $period['key'] === $k ? ' selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select></label>
        <label>From <input type="date" name="from" value="<?= e($period['from']) ?>"></label>
        <label>To <input type="date" name="to" value="<?= e($period['to']) ?>"></label>
        <button type="submit" class="secondary">Show</button>
    </form>
    <p class="muted rep-help">Showing <strong><?= e($period['label']) ?></strong>. For your own dates pick <em>Custom dates</em> and fill in From and To.
        Amounts in different currencies are always kept apart.</p>
    <p class="rep-actions"><a class="button" href="<?= $q(['export' => 'all']) ?>">Export everything</a>
        <span class="muted">One Excel workbook: <?= e(implode(', ', array_map(static fn(string $k): string => $exports[$k], reports_everything()))) ?>.</span></p>
</section>

<?php
// Headline cards, one per currency.
$cards = $data['cards'];
if (!$cards) {
    $cards = [strtoupper(billing_setting('default_currency')) => ['invoiced' => 0, 'invoices' => 0, 'collected' => 0, 'outstanding' => 0,
        'overdue' => 0, 'profit' => 0, 'partial' => false, 'known' => false, 'lines_without_cost' => 0]];
}
echo '<div class="rep-cards">';
foreach ($cards as $cur => $c) {
    echo '<section class="card rep-card"><h2>' . e($cur) . '</h2><dl class="rep-figs">'
        . '<div><dt title="Total of the sent and paid invoices dated in this period. Drafts and void invoices never count.">Invoiced</dt><dd>' . $money($c['invoiced'], $cur)
        . ' <small class="muted">' . (int)$c['invoices'] . ' invoice' . ((int)$c['invoices'] === 1 ? '' : 's') . '</small></dd></div>'
        . '<div><dt title="Every payment recorded in this period: against invoices, and licence payments on a business page.">Collected</dt><dd>' . $money($c['collected'], $cur) . '</dd></div>'
        . '<div><dt title="Still to be paid on all sent invoices, today (whatever their date).">Outstanding today</dt><dd>' . $money($c['outstanding'], $cur) . '</dd></div>'
        . '<div><dt title="Part of the outstanding amount that is past its due date.">Overdue today</dt><dd' . ($c['overdue'] > reports_tol() ? ' class="bill-overdue"' : '') . '>' . $money($c['overdue'], $cur) . '</dd></div>'
        . '<div><dt title="Line totals minus quantity x cost price, on the lines of this period\'s invoices that have a cost price. Before any discount and tax.">Profit</dt><dd>' . $profitCell($c, $cur) . '</dd></div>'
        . '</dl></section>';
}
echo '</div>';

// Free trials (Part 3): started / converted / lost in the period, conversion rate.
$tr = $data['trials'] ?? null;
if (is_array($tr)) {
    echo '<section class="card rep-card"><h2>Free trials</h2><dl class="rep-figs">'
        . '<div><dt title="Free trials started in this period.">Started</dt><dd>' . (int)$tr['started'] . '</dd></div>'
        . '<div><dt title="Trials converted to a paid plan in this period.">Converted</dt><dd>' . (int)$tr['converted'] . '</dd></div>'
        . '<div><dt title="Trials whose last day fell in this period (and has passed) without converting.">Lost</dt><dd>' . (int)$tr['lost'] . '</dd></div>'
        . '<div><dt title="Converted divided by converted plus lost, in this period.">Conversion rate</dt><dd>' . ($tr['rate'] === null ? view_empty() : e(number_format((float)$tr['rate'], 1)) . '%') . '</dd></div>'
        . '<div><dt title="Businesses on a free trial today (not ended, not converted).">On a trial today</dt><dd>' . (int)$tr['active'] . '</dd></div>'
        . '</dl></section>';
}

// Aged debt
$head('Aged debt', 'aged', 'What each client owes today on sent invoices, by how long it is past the due date. "Current" is not yet due.');
if (!$data['aged']) {
    echo '<p class="muted">Nobody owes anything on sent invoices.</p>';
} else {
    echo '<div class="scroll"><table class="list rep-table"><thead><tr>' . view_th('Client', 'Who owes it.') . view_th('Currency', 'Amounts in this currency only.', 'Cur.');
    foreach (REPORTS_BUCKETS as $b => $label) {
        echo view_th($label, $b === 'current' ? 'Not yet past the due date.' : 'Days past the due date.', $b === 'current' ? 'Not yet due' : 'Past due');
    }
    echo view_th('Total', 'Everything this client owes in this currency.') . view_th('Oldest due', 'Due date of the oldest unpaid invoice.') . '</tr></thead><tbody>';
    foreach ($data['aged'] as $r) {
        echo '<tr><td data-label="Client" class="col-name">' . $clientCell($r) . '</td><td data-label="Currency">' . e($r['currency']) . '</td>';
        foreach (REPORTS_BUCKETS as $b => $label) {
            echo '<td data-label="' . e($label) . '" class="nw num' . ($b !== 'current' && $r[$b] > reports_tol() ? ' bill-overdue' : '') . '">'
                . ($r[$b] > reports_tol() ? $money($r[$b], $r['currency']) : view_empty()) . '</td>';
        }
        echo '<td data-label="Total" class="nw num"><strong>' . $money($r['total'], $r['currency']) . '</strong></td>'
            . '<td data-label="Oldest due" class="nw">' . ($r['oldest_due'] ? e(billing_date($r['oldest_due'])) : view_empty()) . '</td></tr>';
    }
    echo '</tbody><tfoot>';
    foreach ($data['aged_totals'] as $cur => $t) {
        echo '<tr class="rep-total"><td data-label="Client">All clients (' . (int)$t['clients'] . ')</td><td data-label="Currency">' . e($cur) . '</td>';
        foreach (REPORTS_BUCKETS as $b => $label) {
            echo '<td data-label="' . e($label) . '" class="nw num">' . $money($t[$b], $cur) . '</td>';
        }
        echo '<td data-label="Total" class="nw num"><strong>' . $money($t['total'], $cur) . '</strong></td><td></td></tr>';
    }
    echo '</tfoot></table></div>';
}

// Sales by month
$head('Sales by month', 'months', 'Sent and paid invoices by the month they are dated, money collected that month, and profit where cost prices are known.');
if (!$data['months']) {
    echo '<p class="muted">No invoices or payments in this period.</p>';
} else {
    echo '<div class="scroll"><table class="list rep-table"><thead><tr>' . view_th('Month', 'Calendar month.') . view_th('Currency', 'Amounts in this currency only.', 'Cur.')
        . view_th('Invoices', 'How many invoices.', 'Count') . view_th('Invoiced', 'Total of those invoices.') . view_th('Collected', 'Payments recorded that month (invoices and licence payments).')
        . view_th('Profit', 'Where cost prices are known.', 'Where known') . '</tr></thead><tbody>';
    foreach ($data['months'] as $r) {
        $label = date('F Y', (int)gmmktime(0, 0, 0, (int)substr($r['month'], 5, 2), 1, (int)substr($r['month'], 0, 4)));
        echo '<tr><td data-label="Month" class="nw">' . e($label) . '</td><td data-label="Currency">' . e($r['currency']) . '</td>'
            . '<td data-label="Invoices" class="num">' . (int)$r['invoices'] . '</td>'
            . '<td data-label="Invoiced" class="nw num">' . $money($r['invoiced'], $r['currency']) . '</td>'
            . '<td data-label="Collected" class="nw num">' . $money($r['collected'], $r['currency']) . '</td>'
            . '<td data-label="Profit" class="nw num">' . $profitCell($r, $r['currency']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

// Sales by client
$head('Sales by client', 'by_client', 'Sent and paid invoices dated in this period per client, what is still owed on them, and what each client paid in the period.');
if (!$data['by_client']) {
    echo '<p class="muted">No invoices or payments in this period.</p>';
} else {
    echo '<div class="scroll"><table class="list rep-table"><thead><tr>' . view_th('Client', 'Company or person.') . view_th('Currency', 'Amounts in this currency only.', 'Cur.')
        . view_th('Invoices', 'How many invoices.', 'Count') . view_th('Invoiced', 'Total of those invoices.') . view_th('Still owed', 'Left to pay on those invoices.', 'On these invoices')
        . view_th('Collected', 'Payments recorded in the period (invoices and licence payments).', 'In the period') . '</tr></thead><tbody>';
    foreach ($data['by_client'] as $r) {
        echo '<tr><td data-label="Client" class="col-name">' . $clientCell($r) . '</td><td data-label="Currency">' . e($r['currency']) . '</td>'
            . '<td data-label="Invoices" class="num">' . (int)$r['invoices'] . '</td>'
            . '<td data-label="Invoiced" class="nw num">' . $money($r['invoiced'], $r['currency']) . '</td>'
            . '<td data-label="Still owed" class="nw num">' . ($r['balance'] > reports_tol() ? $money($r['balance'], $r['currency']) : view_empty()) . '</td>'
            . '<td data-label="Collected" class="nw num">' . $money($r['collected'], $r['currency']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

// Sales by category and by product
$productTable = static function (array $rows, bool $byItem) use ($money, $qty, $profitCell): void {
    echo '<div class="scroll"><table class="list rep-table"><thead><tr>' . view_th('Currency', 'Amounts in this currency only.', 'Cur.')
        . view_th('Category', 'From the saved item; lines typed by hand are "Not a saved item".', 'Of the saved item');
    if ($byItem) {
        echo view_th('Item', 'Saved item, or the line text.') . view_th('Qty', 'Quantity sold.', 'Sold');
    } else {
        echo view_th('Lines', 'Invoice lines.', 'Count');
    }
    echo view_th('Revenue', 'Line totals, before discount and tax.', 'Before discount/tax') . view_th('Profit', 'Where cost prices are known.', 'Where known') . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td data-label="Currency">' . e($r['currency']) . '</td><td data-label="Category">' . e($r['category']) . '</td>';
        if ($byItem) {
            echo '<td data-label="Item" class="col-name">' . view_trunc($r['item']) . ($r['sku'] !== '' ? ' <span class="muted">' . e($r['sku']) . '</span>' : '') . '</td>'
                . '<td data-label="Qty" class="num">' . $qty($r['qty']) . '</td>';
        } else {
            echo '<td data-label="Lines" class="num">' . (int)$r['lines'] . '</td>';
        }
        echo '<td data-label="Revenue" class="nw num">' . $money($r['revenue'], $r['currency']) . '</td>'
            . '<td data-label="Profit" class="nw num">' . $profitCell($r, $r['currency']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
};
$head('Sales by category', 'categories', 'Lines of the sent and paid invoices dated in this period, by the category of the saved item.');
if (!$data['categories']) {
    echo '<p class="muted">No invoice lines in this period.</p>';
} else {
    $productTable($data['categories'], false);
}
$head('Sales by product', 'products', 'The same lines by product (saved item, or the line text when typed by hand).');
if (!$data['products']) {
    echo '<p class="muted">No invoice lines in this period.</p>';
} else {
    $productTable(array_slice($data['products'], 0, 300), true);
    if (count($data['products']) > 300) {
        echo '<p class="muted">Showing the top 300. Export to Excel for all ' . count($data['products']) . '.</p>';
    }
}

// Payments
$head('Payments', 'payments', 'Every payment recorded in this period: against invoices, and licence payments recorded on a business page (no invoice).');
if (!$data['payments']) {
    echo '<p class="muted">No payments in this period.</p>';
} else {
    $shown = array_slice(array_reverse($data['payments']), 0, 300);
    echo '<div class="scroll"><table class="list rep-table"><thead><tr>' . view_th('Date', 'When it was recorded.') . view_th('Invoice', 'Invoice it was paid against, or Licence payment when recorded on a business page. A refund (money given back) is a minus amount; void payments are left out.')
        . view_th('Client', 'Who paid.') . view_th('Amount', 'Amount paid.') . view_th('Method', 'How it was paid.') . view_th('Reference', 'Bank or receipt reference.') . '</tr></thead><tbody>';
    foreach ($shown as $p) {
        echo '<tr><td data-label="Date" class="nw">' . e(billing_date((string)$p['created_at'])) . '</td>'
            . '<td data-label="Invoice" class="nw">' . ((float)$p['amount'] < 0 ? '<span class="pill pill-grace">Refund</span> ' : '') . (!empty($p['document_id']) ? '<a href="document.php?id=' . (int)$p['document_id'] . '">' . e($p['number']) . '</a>' : '<span class="muted">Licence payment</span>') . '</td>'
            . '<td data-label="Client" class="col-name">' . $clientCell($p, 'client_name') . '</td>'
            . '<td data-label="Amount" class="nw num">' . $money($p['amount'], (string)$p['currency']) . '</td>'
            . '<td data-label="Method">' . ((string)$p['method'] !== '' ? e(ucfirst((string)$p['method'])) : view_empty()) . '</td>'
            . '<td data-label="Reference">' . ((string)$p['reference'] !== '' ? view_trunc($p['reference']) : view_empty()) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    if (count($data['payments']) > 300) {
        echo '<p class="muted">Showing the newest 300. Export to Excel for all ' . count($data['payments']) . '.</p>';
    }
}

// Exports with no table on this page
echo '<h2>More exports</h2><section class="card"><p class="muted rep-help">The records themselves, for your own spreadsheets. Invoices and invoice lines are the sent and paid ones dated in this period; quotations are every quotation dated in it; clients is the full list.</p><p class="rep-actions">';
foreach (['invoices', 'lines', 'quotes', 'clients'] as $k) {
    echo '<a class="button secondary" href="' . $q(['export' => $k]) . '">' . e($exports[$k]) . '</a>';
}
echo '</p></section>';
view_foot();
