<?php
// Pure report aggregation (app/reports.php, no database): periods, aged-debt buckets and their boundaries, per-currency
// grouping (never adding currencies), profit with unknown vs zero cost and the partial flag, client grouping, sheets.
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/domain.php';
require __DIR__ . '/../../app/status.php';
require __DIR__ . '/../../app/catalog.php';
require __DIR__ . '/../../app/tenants.php';
require __DIR__ . '/../../app/billing.php';
require __DIR__ . '/../../app/xlsx.php';
require __DIR__ . '/../../app/reports.php';

$today = '2026-11-09';

// Periods
$p = reports_period('this_month', null, null, $today);
t_eq('this month', ['this_month', '2026-11-01', '2026-11-30'], [$p['key'], $p['from'], $p['to']]);
$p = reports_period('last_month', null, null, $today);
t_eq('last month', ['2026-10-01', '2026-10-31'], [$p['from'], $p['to']]);
$p = reports_period('last_month', null, null, '2026-01-15');
t_eq('last month across new year', ['2025-12-01', '2025-12-31'], [$p['from'], $p['to']]);
$p = reports_period('last_month', null, null, '2024-03-31');
t_eq('last month leap February', ['2024-02-01', '2024-02-29'], [$p['from'], $p['to']]);
$p = reports_period('this_year', null, null, $today);
t_eq('this year', ['2026-01-01', '2026-12-31'], [$p['from'], $p['to']]);
$p = reports_period('custom', '2026-03-05', '2026-02-01', $today);
t_eq('custom swapped', ['custom', '2026-02-01', '2026-03-05'], [$p['key'], $p['from'], $p['to']]);
$p = reports_period('custom', '2026-02-30', '2026-03-01', $today);
t_eq('custom invalid falls back to this month', ['this_month', '2026-11-01'], [$p['key'], $p['from']]);
$p = reports_period("x' OR 1=1", null, null, $today);
t_eq('unknown key = this month', 'this_month', $p['key']);
t_eq('label', 'This month (01/11/2026 to 30/11/2026)', reports_period('this_month', null, null, $today)['label']);

// Aged-debt buckets: days past due 0 = current, 1..30, 31..60, 61..90, 91+ = 90+.
$due = static fn(int $daysAgo): string => gmdate('Y-m-d', gmmktime(0, 0, 0, 11, 9 - $daysAgo, 2026));
t_eq('due in future', 'current', reports_age_bucket('2026-12-01', $today));
t_eq('0 days (due today)', 'current', reports_age_bucket($due(0), $today));
t_eq('1 day', '1_30', reports_age_bucket($due(1), $today));
t_eq('30 days', '1_30', reports_age_bucket($due(30), $today));
t_eq('31 days', '31_60', reports_age_bucket($due(31), $today));
t_eq('60 days', '31_60', reports_age_bucket($due(60), $today));
t_eq('61 days', '61_90', reports_age_bucket($due(61), $today));
t_eq('90 days', '61_90', reports_age_bucket($due(90), $today));
t_eq('91 days', '90_plus', reports_age_bucket($due(91), $today));
t_eq('400 days', '90_plus', reports_age_bucket($due(400), $today));
t_eq('no due date', 'current', reports_age_bucket(null, $today));
t_eq('empty due date', 'current', reports_age_bucket('', $today));
t_eq('30 days helper', 30, reports_days_overdue($due(30), $today));
t_eq('days across a year end', 10, reports_days_overdue('2025-12-31', '2026-01-10'));

// Fixtures: clients 1 (Liwonde, MWK + EUR), 2 (Hardware buyer), a one-off customer without a client.
$inv = [
    ['id' => 1, 'kind' => 'invoice', 'number' => '170134', 'status' => 'sent', 'issue_date' => '2026-10-01', 'due_date' => $due(0), 'currency' => 'MWK',
        'total' => '602000.00', 'amount_paid' => '2000.00', 'client_ref' => 1, 'client_name' => 'Liwonde Sun Hotel', 'subtotal' => '602000.00', 'discount' => '0', 'tax' => '0', 'salesperson' => 'John Paul'],
    ['id' => 2, 'kind' => 'invoice', 'number' => '170135', 'status' => 'sent', 'issue_date' => '2026-09-01', 'due_date' => $due(31), 'currency' => 'MWK',
        'total' => '100000.10', 'amount_paid' => '0.00', 'client_ref' => 1, 'client_name' => 'Liwonde Sun Hotel', 'subtotal' => '100000.10', 'discount' => '0', 'tax' => '0', 'salesperson' => ''],
    ['id' => 3, 'kind' => 'invoice', 'number' => '170136', 'status' => 'sent', 'issue_date' => '2026-06-01', 'due_date' => $due(91), 'currency' => 'EUR',
        'total' => '500.00', 'amount_paid' => '100.00', 'client_ref' => 1, 'client_name' => 'Liwonde Sun Hotel', 'subtotal' => '500.00', 'discount' => '0', 'tax' => '0', 'salesperson' => ''],
    ['id' => 4, 'kind' => 'invoice', 'number' => '170137', 'status' => 'paid', 'issue_date' => '2026-11-02', 'due_date' => '2026-11-09', 'currency' => 'MWK',
        'total' => '0.30', 'amount_paid' => '0.30', 'client_ref' => 2, 'client_name' => 'Hardware buyer', 'subtotal' => '0.30', 'discount' => '0', 'tax' => '0', 'salesperson' => ''],
    ['id' => 5, 'kind' => 'invoice', 'number' => '170138', 'status' => 'sent', 'issue_date' => '2026-11-03', 'due_date' => $due(1), 'currency' => 'MWK',
        'total' => '50.00', 'amount_paid' => '0', 'client_ref' => null, 'client_name' => 'Walk-in customer', 'subtotal' => '50', 'discount' => '0', 'tax' => '0', 'salesperson' => ''],
    ['id' => 6, 'kind' => 'invoice', 'number' => '170139', 'status' => 'draft', 'issue_date' => '2026-11-03', 'due_date' => $due(100), 'currency' => 'MWK',
        'total' => '999.00', 'amount_paid' => '0', 'client_ref' => 2, 'client_name' => 'Hardware buyer', 'subtotal' => '999', 'discount' => '0', 'tax' => '0', 'salesperson' => ''],
    ['id' => 7, 'kind' => 'invoice', 'number' => '170140', 'status' => 'void', 'issue_date' => '2026-11-03', 'due_date' => $due(100), 'currency' => 'MWK',
        'total' => '888.00', 'amount_paid' => '0', 'client_ref' => 2, 'client_name' => 'Hardware buyer', 'subtotal' => '888', 'discount' => '0', 'tax' => '0', 'salesperson' => ''],
    ['id' => 8, 'kind' => 'invoice', 'number' => '170141', 'status' => 'sent', 'issue_date' => '2026-11-04', 'due_date' => $due(61), 'currency' => 'MWK',
        'total' => '10.004', 'amount_paid' => '10.00', 'client_ref' => 2, 'client_name' => 'Hardware buyer', 'subtotal' => '10', 'discount' => '0', 'tax' => '0', 'salesperson' => ''],
];
$open = array_values(array_filter($inv, static fn(array $d): bool => $d['status'] === 'sent'));

// Aged debt
$aged = reports_aged_debt($open, $today);
t_eq('aged: rows (balance within tolerance skipped)', 3, count($aged));
t_eq('aged: currencies never mixed, EUR first', ['EUR', 'MWK', 'MWK'], array_column($aged, 'currency'));
t_eq('aged: EUR 90+', 400.0, $aged[0]['90_plus']);
t_eq('aged: EUR total', 400.0, $aged[0]['total']);
$liw = $aged[1];
t_eq('aged: biggest MWK debtor first', 'Liwonde Sun Hotel', $liw['client']);
t_eq('aged: due today is current', 600000.0, $liw['current']);
t_eq('aged: 31 days in 31-60', 100000.1, $liw['31_60']);
t_eq('aged: MWK total', 700000.1, $liw['total']);
t_eq('aged: oldest due', $due(31), $liw['oldest_due']);
t_eq('aged: invoices', 2, $liw['invoices']);
t_eq('aged: one-off customer by name', ['Walk-in customer', 0, 50.0], [$aged[2]['client'], $aged[2]['client_id'], $aged[2]['1_30']]);
$tot = reports_aged_totals($aged);
t_eq('aged totals: per currency', ['EUR', 'MWK'], array_keys($tot));
t_eq('aged totals: MWK total', 700050.1, $tot['MWK']['total']);
t_eq('aged totals: MWK clients', 2, $tot['MWK']['clients']);
t_eq('aged totals: EUR not added to MWK', 400.0, $tot['EUR']['total']);

// Per-currency sums
t_eq('sum by currency', ['EUR' => 500.0, 'MWK' => 702060.40], reports_sum_by_currency(array_filter($inv, 'reports_counts'), 'total'));
t_eq('sum lowercase currency grouped', ['USD' => 3.0], reports_sum_by_currency([['currency' => 'usd', 'v' => 1], ['currency' => 'USD', 'v' => '2']], 'v'));
t_ok('draft never counts', !reports_counts($inv[5]));
t_ok('void never counts', !reports_counts($inv[6]));
t_ok('paid counts', reports_counts($inv[3]));
t_ok('quote never counts', !reports_counts(['kind' => 'quote', 'status' => 'sent']));

// Profit: cost NULL = unknown (partial), cost 0.00 = known zero cost.
$lines = [
    ['currency' => 'MWK', 'issue_date' => '2026-11-02', 'qty' => '2.00', 'line_total' => '200.00', 'cost_price' => '60.00', 'item' => 'Router', 'catalog_item_id' => 5, 'category' => 'hardware', 'sku' => 'RT-1'],
    ['currency' => 'MWK', 'issue_date' => '2026-11-02', 'qty' => '1.00', 'line_total' => '50.00', 'cost_price' => '0.00', 'item' => 'Setup', 'catalog_item_id' => 6, 'category' => 'service', 'sku' => ''],
    ['currency' => 'MWK', 'issue_date' => '2026-11-03', 'qty' => '1.00', 'line_total' => '30.00', 'cost_price' => null, 'item' => 'Cable (typed)', 'catalog_item_id' => null, 'category' => null, 'sku' => null],
    ['currency' => 'EUR', 'issue_date' => '2026-10-15', 'qty' => '3.00', 'line_total' => '0.30', 'cost_price' => '0.05', 'item' => 'Router', 'catalog_item_id' => 5, 'category' => 'hardware', 'sku' => 'RT-1'],
    ['currency' => 'MWK', 'issue_date' => '2026-11-04', 'qty' => '1.00', 'line_total' => '100.00', 'cost_price' => '40.00', 'item' => 'Router', 'catalog_item_id' => 5, 'category' => 'hardware', 'sku' => 'RT-1'],
];
$pr = reports_profit($lines);
t_eq('profit: currencies', ['EUR', 'MWK'], array_keys($pr));
t_eq('profit MWK: (200-120) + (50-0) + (100-40)', 190.0, $pr['MWK']['profit']);
t_eq('profit MWK: revenue counts every line', 380.0, $pr['MWK']['revenue']);
t_eq('profit MWK: cost of costed lines', 160.0, $pr['MWK']['cost']);
t_eq('profit MWK: partial (one line without cost)', [true, 1, true], [$pr['MWK']['partial'], $pr['MWK']['lines_without_cost'], $pr['MWK']['known']]);
t_eq('profit EUR: 0.30 - 3 x 0.05', 0.15, $pr['EUR']['profit']);
t_eq('profit EUR: zero-cost-free, complete', false, $pr['EUR']['partial']);
$z = reports_profit([['currency' => 'MWK', 'qty' => 1, 'line_total' => 10, 'cost_price' => '0.00']]);
t_eq('zero cost is known, not partial', [10.0, false, true], [$z['MWK']['profit'], $z['MWK']['partial'], $z['MWK']['known']]);
$u = reports_profit([['currency' => 'MWK', 'qty' => 1, 'line_total' => 10, 'cost_price' => null]]);
// The invoice's own discount, as on the document page (billing_profit): off revenue always, off profit when fully costed.
$dl = [['document_id' => 9, 'doc_discount' => '10000.00', 'currency' => 'MWK', 'qty' => 1, 'line_total' => 100000, 'cost_price' => '60000']];
$dp = reports_profit($dl);
t_eq('discount: profit 100,000 - 60,000 - 10,000', [90000.0, 30000.0], [$dp['MWK']['revenue'], $dp['MWK']['profit']]);
t_eq('discount: same as the document page', billing_profit([['qty' => 1, 'line_total' => 100000, 'cost_price' => '60000']], 10000)['profit'], $dp['MWK']['profit']);
$dl2 = array_merge($dl, [['document_id' => 9, 'doc_discount' => '10000.00', 'currency' => 'MWK', 'qty' => 1, 'line_total' => 5000, 'cost_price' => null]]);
$dp2 = reports_profit($dl2);
t_eq('discount: counted once per invoice, kept out of profit when a line has no cost', [95000.0, 40000.0, true], [$dp2['MWK']['revenue'], $dp2['MWK']['profit'], $dp2['MWK']['partial']]);
t_eq('all unknown: not known, profit 0', [false, true, 0.0], [$u['MWK']['known'], $u['MWK']['partial'], $u['MWK']['profit']]);
t_eq('profit note partial', 'Partial: 1 line(s) without cost', reports_profit_note($pr['MWK']));
t_eq('profit note none', 'No cost prices', reports_profit_note($u['MWK']));
t_eq('profit note complete', '', reports_profit_note($pr['EUR']));

// Payments: every payment is collected, licence payments without an invoice too (2026-11-10).
$pay = [
    ['id' => 1, 'document_id' => 1, 'amount' => '2000.00', 'currency' => 'MWK', 'created_at' => '2026-11-05 10:00:00', 'client_ref' => 1, 'client_name' => 'Liwonde Sun Hotel', 'number' => '170134', 'method' => 'bank', 'reference' => 'TT1'],
    ['id' => 2, 'document_id' => 3, 'amount' => '100.00', 'currency' => 'EUR', 'created_at' => '2026-10-20 09:00:00', 'client_ref' => 1, 'client_name' => 'Liwonde Sun Hotel', 'number' => '170136', 'method' => 'bank', 'reference' => 'TT2'],
    ['id' => 3, 'document_id' => null, 'amount' => '777.00', 'currency' => 'MWK', 'created_at' => '2026-11-05 11:00:00', 'client_ref' => 1, 'client_name' => 'Liwonde Sun Hotel', 'number' => null, 'method' => null, 'reference' => 'licence'],
    ['id' => 4, 'document_id' => 4, 'amount' => '0.10', 'currency' => 'MWK', 'created_at' => '2026-11-06 11:00:00', 'client_ref' => 2, 'client_name' => 'Hardware buyer', 'number' => '170137', 'method' => 'cash', 'reference' => ''],
    ['id' => 5, 'document_id' => 4, 'amount' => '0.20', 'currency' => 'MWK', 'created_at' => '2026-11-06 12:00:00', 'client_ref' => 2, 'client_name' => 'Hardware buyer', 'number' => '170137', 'method' => 'cash', 'reference' => ''],
];
$cards = reports_cards($inv, $pay, $open, $lines, $today);
t_eq('cards: currencies', ['EUR', 'MWK'], array_keys($cards));
t_eq('cards MWK invoiced (sent+paid only)', 702060.4, $cards['MWK']['invoiced']);
t_eq('cards MWK invoices count', 5, $cards['MWK']['invoices']);
t_eq('cards MWK collected (with the 777 licence payment, 0.1+0.2)', 2777.3, $cards['MWK']['collected']);
t_eq('cards EUR collected', 100.0, $cards['EUR']['collected']);
t_eq('cards MWK outstanding', 700050.1, $cards['MWK']['outstanding']);
t_eq('cards MWK overdue (due today is not overdue)', 100050.1, $cards['MWK']['overdue']);
t_eq('cards EUR outstanding = overdue', [400.0, 400.0], [$cards['EUR']['outstanding'], $cards['EUR']['overdue']]);
t_eq('cards MWK profit partial', [190.0, true], [$cards['MWK']['profit'], $cards['MWK']['partial']]);
t_eq('cards EUR profit', [0.15, false], [$cards['EUR']['profit'], $cards['EUR']['partial']]);

// Sales by month
$months = reports_by_month($inv, $pay, $lines);
$m = [];
foreach ($months as $r) {
    $m[$r['month'] . ' ' . $r['currency']] = $r;
}
t_eq('months: keys sorted, currencies apart', ['2026-06 EUR', '2026-09 MWK', '2026-10 EUR', '2026-10 MWK', '2026-11 MWK'], array_keys($m));
t_eq('months: Nov MWK invoiced excludes draft/void', 60.30, round($m['2026-11 MWK']['invoiced'], 2));
t_eq('months: Nov MWK invoices', 3, $m['2026-11 MWK']['invoices']);
t_eq('months: Nov MWK collected (licence payment included)', 2777.3, $m['2026-11 MWK']['collected']);
t_eq('months: Nov MWK profit partial', [190.0, true], [$m['2026-11 MWK']['profit'], $m['2026-11 MWK']['partial']]);
t_eq('months: Oct EUR collected + profit', [100.0, 0.15], [$m['2026-10 EUR']['collected'], $m['2026-10 EUR']['profit']]);
t_eq('months: Jun EUR invoiced', 500.0, $m['2026-06 EUR']['invoiced']);

// Sales by client
$bc = reports_by_client($inv, $pay);
$k = [];
foreach ($bc as $r) {
    $k[$r['client'] . ' ' . $r['currency']] = $r;
}
t_eq('by client: rows, largest invoiced first per currency', ['Liwonde Sun Hotel EUR', 'Liwonde Sun Hotel MWK', 'Walk-in customer MWK', 'Hardware buyer MWK'], array_keys($k));
t_eq('by client: Liwonde MWK', [2, 702000.1, 700000.1, 2777.0], [$k['Liwonde Sun Hotel MWK']['invoices'], $k['Liwonde Sun Hotel MWK']['invoiced'], $k['Liwonde Sun Hotel MWK']['balance'], $k['Liwonde Sun Hotel MWK']['collected']]);
t_eq('by client: Hardware buyer (draft/void excluded, paid balance 0)', [2, 10.3, 0.0, 0.3], [$k['Hardware buyer MWK']['invoices'], round($k['Hardware buyer MWK']['invoiced'], 2), $k['Hardware buyer MWK']['balance'], $k['Hardware buyer MWK']['collected']]);

// Client-name fallback grouping: same id groups even with different names; no id groups by name (case-insensitive).
t_eq('client key by id', 'c7', reports_client_key(['client_ref' => '7', 'client_name' => 'X']));
t_eq('client key by name', 'n:walk-in', reports_client_key(['client_ref' => null, 'client_name' => ' Walk-In ']));

// Sales by product / category
$prod = reports_by_product($lines, 'item');
$byKey = [];
foreach ($prod as $r) {
    $byKey[$r['currency'] . ' ' . $r['item']] = $r;
}
t_eq('products: Router MWK merged by catalog id', [3.0, 2, 300.0, 140.0, false], [$byKey['MWK Router']['qty'], $byKey['MWK Router']['lines'], $byKey['MWK Router']['revenue'], $byKey['MWK Router']['profit'], $byKey['MWK Router']['partial']]);
t_ok('products: Router EUR kept apart', isset($byKey['EUR Router']));
t_eq('products: typed line not known', [false, 'Not a saved item'], [$byKey['MWK Cable (typed)']['known'], $byKey['MWK Cable (typed)']['category']]);
t_eq('products: zero-cost service fully profit', 50.0, $byKey['MWK Setup']['profit']);
$cat = reports_by_product($lines, 'category');
$c = [];
foreach ($cat as $r) {
    $c[$r['currency'] . ' ' . $r['category']] = $r;
}
t_eq('categories: keys', ['EUR Hardware', 'MWK Hardware', 'MWK Not a saved item', 'MWK Service'], (function (array $a): array { sort($a); return $a; })(array_keys($c)));
t_eq('categories: MWK hardware', [2, 300.0, 140.0], [$c['MWK Hardware']['lines'], $c['MWK Hardware']['revenue'], $c['MWK Hardware']['profit']]);

// Sheets: shape and values for the Excel exports.
$data = ['today' => $today, 'invoices' => array_values(array_filter($inv, 'reports_counts')), 'payments' => $pay,
    'lines' => array_map(static fn($l) => $l + ['number' => '170134', 'client_name' => 'X', 'description' => '', 'unit_price' => '1'], $lines),
    'aged' => $aged, 'months' => $months, 'products' => $prod, 'categories' => $cat, 'by_client' => $bc];
foreach (array_keys(reports_exports()) as $key) {
    $sh = reports_sheet($key, $data, ['quotes' => [], 'clients' => []]);
    t_ok("sheet $key built", is_array($sh) && $sh['name'] !== '' && count($sh['columns']) > 0);
    foreach ($sh['rows'] as $i => $row) {
        t_ok("sheet $key row $i width matches columns", count($row) === count($sh['columns']));
    }
}
t_eq('sheet unknown', null, reports_sheet('nope', $data));
$sh = reports_sheet('invoices', $data);
t_eq('invoices sheet: no draft/void rows', 6, count($sh['rows']));
t_eq('invoices sheet: overdue flag on 31-day invoice', 'Yes', $sh['rows'][1][12]);
t_eq('invoices sheet: due-today invoice not overdue', 'No', $sh['rows'][0][12]);
$sh = reports_sheet('lines', $data);
t_eq('lines sheet: unknown cost leaves cost and profit blank', [null, null], [$sh['rows'][2][11], $sh['rows'][2][12]]);
t_eq('lines sheet: zero cost gives full profit', 50.0, $sh['rows'][1][12]);
$sh = reports_sheet('payments', $data);
t_eq('payments sheet: every payment, the licence one labelled', [5, 'Licence payment', 777.0], [count($sh['rows']), $sh['rows'][2][1], (float)$sh['rows'][2][4]]);
t_eq('everything = the contract\'s 8 sheets', ['Invoices', 'Invoice lines', 'Payments', 'Quotations', 'Clients', 'Aged debt', 'Sales by month', 'Sales by product'],
    array_map(static fn($k) => reports_exports()[$k], reports_everything()));
$wb = xlsx_build(array_map(static fn($k) => reports_sheet($k, $data, ['quotes' => [], 'clients' => []]), reports_everything()));
t_ok('everything workbook builds', substr($wb, 0, 4) === "PK\x03\x04" && strpos($wb, "PK\x05\x06") !== false);
t_eq('filename', 'ProManaged IT Sales report 2026-11-01 to 2026-11-30.xlsx', reports_filename('Sales report', reports_period('this_month', null, null, $today)));
t_done('reports');
