<?php
// Clients, recurring invoices, profit and currency rules (BILLING.md Part 2).
// Pure tests always run (no database). The database smoke test runs only with --db: everything it writes happens inside
// ONE transaction that is rolled back at the end, automations are switched off and nothing is ever emailed.
//   php scripts/tests/clients_test.php         pure tests
//   php scripts/tests/clients_test.php --db    pure tests + rolled-back database smoke test
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/domain.php';
require __DIR__ . '/../../app/status.php';
require __DIR__ . '/../../app/catalog.php';
require __DIR__ . '/../../app/tenants.php';
require __DIR__ . '/../../app/billing.php'; // loads clients.php and recurring.php

// --- Client validation ---
[$err, $c] = client_validate(['kind' => 'company', 'name' => ' Acme Ltd ', 'email' => 'ap@acme.mw', 'cc_emails' => 'b@acme.mw; B@acme.mw, c@acme.mw',
    'currency' => 'usd', 'phone' => '+265 999 123 456']);
t_eq('company valid', [], $err);
t_eq('name trimmed', 'Acme Ltd', $c['name']);
t_eq('cc normalised and deduplicated', 'b@acme.mw, c@acme.mw', $c['cc_emails']);
t_eq('currency upper', 'USD', $c['currency']);
t_eq('active by default', 1, $c['is_active']);
[$err, $c] = client_validate(['kind' => 'individual', 'name' => '']);
t_eq('individual needs a name', ['The person\'s full name is required.'], $err);
t_eq('kind individual kept', 'individual', $c['kind']);
[, $c] = client_validate(['kind' => 'nonsense', 'name' => 'X', 'is_active' => '0'], 'EUR');
t_eq('unknown kind = company', 'company', $c['kind']);
t_eq('default currency used', 'EUR', $c['currency']);
t_eq('inactive', 0, $c['is_active']);
[$err] = client_validate(['name' => 'X', 'email' => 'nope', 'cc_emails' => 'a@b.com bad', 'currency' => 'MW', 'phone' => 'call me']);
t_eq('four errors (email, cc, currency, phone)', 4, count($err));
t_ok('tenant_id never taken from a form', !array_key_exists('tenant_id', client_validate(['name' => 'X', 'tenant_id' => '5'])[1]));
t_eq('like escapes wildcards', '%50\\%\\_off\\\\%', client_like('50%_off\\'));

// --- Recipients: the client's email first, the printed customer email only as a fallback ---
t_eq('client email preferred', [['ap@acme.mw'], ['b@acme.mw']],
    client_recipients(['email' => 'ap@acme.mw', 'cc_emails' => 'b@acme.mw, AP@acme.mw'], ['customer_email' => 'old@acme.mw']));
t_eq('fallback to customer email', [['old@acme.mw'], []], client_recipients(['email' => '', 'cc_emails' => ''], ['customer_email' => 'old@acme.mw']));
t_eq('no client, no doc', [[], []], client_recipients(null, null));

// --- Calendar-safe months ---
t_eq('31 Jan + 1 = 28 Feb', '2026-02-28', recurring_add_months('2026-01-31', 1));
t_eq('31 Jan + 1 = 29 Feb in a leap year', '2028-02-29', recurring_add_months('2028-01-31', 1));
t_eq('30 Nov + 3 = 28 Feb', '2027-02-28', recurring_add_months('2026-11-30', 3));
t_eq('30 Nov + 3 = 29 Feb before a leap year', '2028-02-29', recurring_add_months('2027-11-30', 3));
t_eq('29 Feb + 12 = 28 Feb', '2025-02-28', recurring_add_months('2024-02-29', 12));
t_eq('2100 is not a leap year', '2100-02-28', recurring_add_months('2100-01-31', 1));
t_eq('2000 is a leap year', '2000-02-29', recurring_add_months('2000-01-31', 1));
t_eq('over the year end', '2027-01-15', recurring_add_months('2026-12-15', 1));
t_eq('31 Mar + 1 = 30 Apr', '2026-04-30', recurring_add_months('2026-03-31', 1));
t_eq('yearly keeps the day', '2027-01-31', recurring_add_months('2026-01-31', 12));
t_eq('datetime input', '2026-02-28', recurring_add_months('2026-01-31 08:00:00', 1));

// --- Due schedules ---
$r = ['is_active' => 1, 'next_date' => '2026-11-09', 'end_date' => null];
t_ok('due today', recurring_is_due($r, '2026-11-09'));
t_ok('not due tomorrow', !recurring_is_due($r, '2026-11-08'));
t_ok('paused is never due', !recurring_is_due(['is_active' => 0] + $r, '2026-12-01'));
t_ok('past end date', !recurring_is_due(['end_date' => '2026-11-01'] + $r, '2026-11-09'));
t_ok('end date on next date still runs', recurring_is_due(['end_date' => '2026-11-09'] + $r, '2026-11-09'));

// --- Recurring validation ---
[$err, $rv] = recurring_validate(['client_id' => '3', 'title' => 'Hosting', 'interval_months' => '3', 'next_date' => '2026-12-01', 'due_days' => '',
    'currency' => 'mwk', 'send_mode' => 'auto'], [['qty' => '1', 'item' => 'Web Hosting', 'unit_price' => '100000', 'cost_price' => '40000', 'catalog_item_id' => '7']]);
t_eq('schedule valid', [], $err);
t_eq('schedule interval', 3, $rv['interval_months']);
t_eq('due days default', 7, $rv['due_days']);
t_eq('schedule line keeps cost', 40000.0, $rv['items'][0]['cost_price']);
t_eq('schedule line keeps catalog id', 7, $rv['items'][0]['catalog_item_id']);
[$err, $rv] = recurring_validate(['client_id' => '', 'title' => '', 'interval_months' => '0', 'next_date' => '2026-02-30', 'end_date' => '2020-01-01',
    'send_mode' => 'weird'], []);
t_eq('schedule errors (client, title, interval, date, lines)', 5, count($err));
t_eq('unknown send mode = approve', 'approve', $rv['send_mode']);

// --- Lines keep cost price and catalog id ---
[$err, $items] = billing_lines_validate([['qty' => '2', 'item' => 'Switch', 'unit_price' => '150000', 'cost_price' => '100000', 'catalog_item_id' => '4'],
    ['qty' => '1', 'item' => 'Install', 'unit_price' => '50000', 'cost_price' => '']]);
t_eq('lines valid', [], $err);
t_eq('cost kept', 100000.0, $items[0]['cost_price']);
t_eq('empty cost = unknown', null, $items[1]['cost_price']);
[$err] = billing_lines_validate([['qty' => '1', 'item' => 'X', 'unit_price' => '1', 'cost_price' => '-5']]);
t_eq('negative cost rejected', 1, count($err));
[, $doc] = billing_doc_validate(['kind' => 'invoice', 'client_id' => '9', 'customer_name' => 'A', 'issue_date' => '2026-11-09', 'currency' => 'MWK',
    'extend_months' => '2'], [['qty' => '1', 'item' => 'X', 'unit_price' => '1']]);
t_eq('client id read', 9, $doc['client_id']);

// --- Profit ---
$p = billing_profit([['qty' => 2, 'unit_price' => 150000, 'cost_price' => 100000], ['qty' => 1, 'unit_price' => 50000, 'cost_price' => 20000]], 0);
t_eq('profit complete', 130000.0, $p['profit']);
t_eq('cost', 220000.0, $p['cost']);
t_ok('complete flag', $p['complete'] && !$p['partial']);
t_eq('margin %', 37.1, $p['margin']);
$p = billing_profit([['qty' => 2, 'unit_price' => 150000, 'cost_price' => 100000], ['qty' => 1, 'unit_price' => 50000, 'cost_price' => 20000]], 10000);
t_eq('discount lowers profit', 120000.0, $p['profit']);
$p = billing_profit([['qty' => 2, 'unit_price' => 150000, 'cost_price' => 100000], ['qty' => 1, 'unit_price' => 50000, 'cost_price' => null]], 10000);
t_ok('partial flag', $p['partial'] && !$p['complete']);
t_eq('partial profit on costed lines only (discount ignored)', 100000.0, $p['profit']);
t_eq('unknown lines counted', 1, $p['unknown_lines']);
$p = billing_profit([['qty' => 1, 'unit_price' => 100, 'cost_price' => null]]);
t_ok('no cost known', !$p['complete'] && !$p['partial']);
t_eq('profit rounding', 0.2, billing_profit([['qty' => 3, 'unit_price' => 0.1, 'cost_price' => 0.0333]])['profit'] + 0.0);

// --- Currency grouping: never added together ---
$by = billing_sum_by_currency([['currency' => 'MWK', 'owed' => 100000, 'overdue' => 0], ['currency' => 'eur', 'owed' => 50.5, 'overdue' => 50.5],
    ['currency' => 'MWK', 'owed' => 0.1, 'overdue' => 0.2]], ['owed', 'overdue']);
t_eq('per currency sums', ['EUR' => ['owed' => 50.5, 'overdue' => 50.5], 'MWK' => ['owed' => 100000.1, 'overdue' => 0.2]], $by);
[$cur, $main, $other] = billing_pick_currency($by, 'MWK', ['owed', 'overdue']);
t_eq('home currency is main', 'MWK', $cur);
t_eq('other currencies apart', ['EUR'], array_keys($other));
[$cur, $main, $other] = billing_pick_currency(['EUR' => ['owed' => 5.0]], 'MWK', ['owed']);
t_eq('first currency when home absent', 'EUR', $cur);
[$cur, $main] = billing_pick_currency([], 'MWK', ['owed', 'overdue']);
t_eq('empty = zeros in home', ['MWK', ['owed' => 0.0, 'overdue' => 0.0]], [$cur, $main]);

// --- Statement: debits and credits stay consistent ---
$inv = [
    ['id' => 1, 'number' => '170134', 'issue_date' => '2026-10-01', 'total' => '1000.00', 'description' => 'Invoice 170134'],
    ['id' => 2, 'number' => '170135', 'issue_date' => '2026-12-01', 'total' => '500.00', 'description' => 'Invoice 170135'], // future-dated
];
$pays = [
    ['id' => 10, 'document_id' => 1, 'created_at' => '2026-10-05 10:00:00', 'reference' => 'TRX1', 'amount' => '400.00', 'method' => 'cash'],
    ['id' => 11, 'document_id' => 2, 'created_at' => '2026-11-08 10:00:00', 'reference' => 'TRX2', 'amount' => '500.00', 'method' => 'cash'],
    ['id' => 12, 'document_id' => 1, 'created_at' => '2026-11-20 10:00:00', 'reference' => 'TRX3', 'amount' => '100.00', 'method' => 'cash'],
];
$b = billing_statement_build($inv, $pays, null, '2026-11-09');
t_eq('future invoice and its payment both left out', 2, count($b['lines']));
t_eq('closing = 1000 - 400', 600.0, $b['closing']);
$b = billing_statement_build($inv, $pays, '2026-10-03', '2026-12-31');
t_eq('opening carries earlier invoice', 1000.0, $b['opening']);
t_eq('closing with everything (1500 invoiced - 1000 paid)', 500.0, $b['closing']);
t_eq('lines after opening', 4, count($b['lines']));

// --- Zero-total invoices count as paid when sent ---
t_ok('zero total sent settles', billing_is_zero_settle(['kind' => 'invoice', 'status' => 'sent', 'total' => '0.00', 'amount_paid' => '0.00']));
t_ok('draft does not settle', !billing_is_zero_settle(['kind' => 'invoice', 'status' => 'draft', 'total' => '0.00', 'amount_paid' => '0.00']));
t_ok('non-zero does not settle', !billing_is_zero_settle(['kind' => 'invoice', 'status' => 'sent', 'total' => '0.01', 'amount_paid' => '0.00']));
t_ok('quote never settles', !billing_is_zero_settle(['kind' => 'quote', 'status' => 'sent', 'total' => '0.00', 'amount_paid' => '0.00']));

// --- One-time form tokens ---
$_SESSION = [];
$tok = billing_once_issue('doc_pay_5');
t_ok('token consumed once', billing_once_consume('doc_pay_5', $tok));
t_ok('second submit refused', !billing_once_consume('doc_pay_5', $tok));
t_ok('other scope refused', !billing_once_consume('doc_pay_6', billing_once_issue('doc_pay_5')));
t_ok('empty token refused', !billing_once_consume('doc_pay_5', ''));

// --- Catalog ---
[$err, $ci] = billing_catalog_validate(['item' => 'Cisco switch', 'unit_price' => '150000', 'currency' => 'mwk', 'category' => 'hardware', 'sku' => 'CSW-24',
    'unit' => 'piece', 'cost_price' => '100000', 'is_active' => '1']);
t_eq('catalog item valid', [], $err);
t_eq('catalog category', 'hardware', $ci['category']);
[$err] = billing_catalog_validate(['item' => 'X', 'unit_price' => '1', 'currency' => 'MWK', 'category' => 'cars', 'sku' => 'a b', 'cost_price' => 'x']);
t_eq('catalog errors (category, sku, cost)', 3, count($err));
$g = billing_catalog_grouped([['category' => 'hardware', 'item' => 'A'], ['category' => 'service', 'item' => 'B'], ['category' => '??', 'item' => 'C']]);
t_eq('grouped in category order', ['service', 'hardware', 'other'], array_keys($g));

// --- Automation queue: nothing fires inside a transaction or when switched off ---
billing_events_clear();
billing_event('payment_recorded', ['payment_id' => 1]);
t_eq('event queued', 1, count(billing_events_queue()));
billing_events_clear();
t_eq('queue cleared', 0, count(billing_events_queue()));

if (!in_array('--db', $argv, true)) {
    t_done('clients_test');
}

// =====================================================================================================================
// Database smoke test: ONE transaction, rolled back. No email: automations off, billing_send never called.
// =====================================================================================================================
require_once __DIR__ . '/../../app/env.php';
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2));
}
env_load(APP_ROOT . '/.env');
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/audit.php';
if (!function_exists('client_ip')) {
    function client_ip(): string
    {
        return '127.0.0.1';
    }
}
billing_automation_enabled(false);
$pdo = db();
$counts = static function () use ($pdo): array {
    $o = [];
    foreach (['cp_clients', 'cp_documents', 'cp_document_items', 'cp_payments', 'cp_recurring', 'cp_catalog_items', 'cp_audit_log', 'cp_outbox', 'cp_email_log'] as $t) {
        $o[$t] = (int)$pdo->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn();
    }
    $o['tenants'] = $pdo->query('SELECT GROUP_CONCAT(CONCAT(id, ":", paid_until, ":", IFNULL(contact_name, "")) ORDER BY id) FROM cp_tenants')->fetchColumn();
    $o['settings'] = $pdo->query('SELECT GROUP_CONCAT(CONCAT(setting_key, "=", setting_value) ORDER BY setting_key) FROM cp_settings')->fetchColumn();
    return $o;
};
$before = $counts();
$today = status_today();
$pdo->beginTransaction();
try {
    billing_settings_cache(null, true);
    // 1. An individual client, not hosted.
    $cid = client_save($pdo, ['kind' => 'individual', 'name' => 'Smoke Test Person', 'email' => 'smoke@example.invalid', 'currency' => 'MWK', 'source' => 'test'], 0);
    $cl = client_find($pdo, $cid);
    t_ok('db: individual client created', $cl && $cl['kind'] === 'individual' && $cl['tenant_id'] === null);
    t_eq('db: client_created queued, not fired (inside the transaction)', 'client_created', billing_events_queue()[0][0] ?? '');
    billing_events_clear();

    // 2. A hardware catalog item with a cost price; an invoice using it.
    $catId = billing_catalog_save($pdo, 0, ['category' => 'hardware', 'sku' => 'SMK-1', 'item' => 'Smoke switch', 'description' => '', 'unit_price' => '150000',
        'unit' => 'piece', 'cost_price' => '100000', 'currency' => 'MWK', 'is_active' => 1, 'sort' => 0], 0);
    $docId = billing_doc_save($pdo, ['id' => 0, 'kind' => 'invoice', 'client_id' => $cid, 'customer_name' => 'Smoke Test Person', 'issue_date' => $today,
        'due_date' => status_add_days($today, 7), 'currency' => 'MWK', 'discount' => 0, 'tax_rate' => 0, 'extend_months' => 0],
        [['qty' => 2, 'item' => 'Smoke switch', 'description' => '', 'unit_price' => 150000, 'cost_price' => 100000, 'catalog_item_id' => $catId],
         ['qty' => 1, 'item' => 'Installation', 'description' => '', 'unit_price' => 50000, 'cost_price' => 20000, 'catalog_item_id' => null]], 0);
    $d = billing_doc($pdo, $docId);
    t_ok('db: invoice belongs to the client, no tenant', (int)$d['client_id'] === $cid && $d['tenant_id'] === null && (int)$d['client']['id'] === $cid);
    t_eq('db: total', 350000.0, (float)$d['total']);
    t_eq('db: cost and catalog id stored', ['100000.00', $catId], [(string)$d['items'][0]['cost_price'], (int)$d['items'][0]['catalog_item_id']]);
    t_eq('db: profit', 130000.0, billing_profit($d['items'], (float)$d['discount'])['profit']);
    $threw = false;
    try {
        billing_doc_save($pdo, ['id' => $docId, 'client_id' => $cid, 'customer_name' => 'X', 'issue_date' => $today, 'currency' => 'MWK', 'extend_months' => 3],
            [['qty' => 1, 'item' => 'X', 'unit_price' => 1]], 0);
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    t_ok('db: licence extension refused for a client with no business', $threw);
    t_eq('db: mark sent', '', billing_set_status($pdo, $docId, 'sent', 0));

    // 3. Part payment, overpayment refused, full payment.
    $r1 = billing_record_payment($pdo, $docId, 100000, 'SMOKE-1', 'cash', 0);
    t_ok('db: part payment ok, not full', $r1['ok'] && !$r1['paid_in_full']);
    $p1 = billing_payment($pdo, $r1['payment_id']);
    t_ok('db: payment has client, no tenant, dates set', (int)$p1['client_id'] === $cid && $p1['tenant_id'] === null && $p1['paid_until_before'] === $today);
    t_eq('db: payment_recorded queued', 'payment_recorded', billing_events_queue()[0][0] ?? '');
    billing_events_clear();
    $r2 = billing_record_payment($pdo, $docId, 250000.01, 'SMOKE-2', 'cash', 0);
    t_ok('db: overpayment refused', !$r2['ok']);
    $r3 = billing_record_payment($pdo, $docId, 250000, 'SMOKE-3', 'bank_transfer', 0);
    t_ok('db: full payment', $r3['ok'] && $r3['paid_in_full']);
    t_eq('db: invoice paid', 'paid', billing_doc_row($pdo, $docId)['status']);
    billing_events_clear();
    $sum = client_summary($pdo, $cid);
    t_eq('db: summary per currency', ['MWK' => ['invoiced' => 350000.0, 'received' => 350000.0, 'owed' => 0.0, 'overdue' => 0.0]], $sum['totals']);
    $stm = client_statement($pdo, $cid, null, null, null);
    t_eq('db: statement lines and closing', [3, 0.0], [count($stm['lines']), $stm['closing']]);
    t_eq('db: statement party is the client', 'Smoke Test Person', $stm['tenant']['name']);
    $rd = billing_receipt_doc($pdo, $p1);
    t_eq('db: receipt document', (string)$d['number'], (string)$rd['number']);
    t_eq('db: client payments', 2, count(client_payments($pdo, $cid)));
    t_eq('db: search escapes % (no match)', 0, count(client_search($pdo, '%%%')));
    t_ok('db: search by name', count(client_search($pdo, 'Smoke Test')) === 1);
    billing_owed_all($pdo);

    // 4. Zero-total invoice marked sent = paid.
    $zId = billing_doc_save($pdo, ['id' => 0, 'kind' => 'invoice', 'client_id' => $cid, 'customer_name' => 'Smoke Test Person', 'issue_date' => $today,
        'currency' => 'MWK'], [['qty' => 1, 'item' => 'Free setup', 'unit_price' => 0]], 0);
    billing_set_status($pdo, $zId, 'sent', 0);
    t_eq('db: zero-total invoice paid when sent', 'paid', billing_doc_row($pdo, $zId)['status']);

    // 5. Recurring: one due today runs once (second run refused); one from 31 Jan runs twice (28 Feb, then 31 Mar).
    [$e1, $ra] = recurring_validate(['client_id' => (string)$cid, 'title' => 'Smoke hosting', 'interval_months' => '1', 'next_date' => $today,
        'currency' => 'MWK', 'send_mode' => 'draft'], [['qty' => '1', 'item' => 'Hosting', 'unit_price' => '100000', 'cost_price' => '30000']]);
    $rA = recurring_save($pdo, $ra, 0);
    t_ok('db: schedule saved', $e1 === [] && $rA > 0);
    t_ok('db: due today listed', in_array($rA, array_map('intval', array_column(recurring_due($pdo, $today), 'id')), true));
    $run1 = recurring_run_one($pdo, $rA, 0, $today);
    t_ok('db: run made a draft invoice', $run1['ok'] && billing_doc_row($pdo, $run1['document_id'])['status'] === 'draft');
    t_eq('db: next date one month on', recurring_add_months($today, 1), recurring_find($pdo, $rA)['next_date']);
    $run2 = recurring_run_one($pdo, $rA, 0, $today);
    t_ok('db: second run the same day refused', !$run2['ok'] && $run2['error'] !== '');
    [, $rb] = recurring_validate(['client_id' => (string)$cid, 'title' => 'Smoke month end', 'interval_months' => '1', 'next_date' => '2026-01-31',
        'currency' => 'MWK', 'send_mode' => 'approve'], [['qty' => '1', 'item' => 'Support', 'unit_price' => '1000']]);
    $rB = recurring_save($pdo, $rb, 0);
    $b1 = recurring_run_one($pdo, $rB, 0, $today);
    $b2 = recurring_run_one($pdo, $rB, 0, $today);
    t_eq('db: 31 Jan schedule ran twice (a month end stays a month end)', ['2026-02-28', '2026-03-31'], [$b1['next_date'], $b2['next_date']]);
    t_eq('db: recurring copies cost', '30000.00', (string)billing_doc_items($pdo, $run1['document_id'])[0]['cost_price']);
    t_ok('db: upcoming board list', count(recurring_upcoming($pdo, $today, 7)) >= 1);

    // 6. Hosted business: client <-> business stay in step; older documents map to the client.
    $t1 = $pdo->query('SELECT id FROM cp_tenants ORDER BY id LIMIT 1')->fetchColumn();
    if ($t1) {
        $hc = client_for_tenant($pdo, (int)$t1);
        $upd = $hc;
        $upd['contact_name'] = 'Smoke Contact';
        client_save($pdo, $upd, 0);
        t_eq('db: client -> business contact synced', 'Smoke Contact', tenant_find($pdo, (int)$t1)['contact_name']);
        $pdo->prepare('UPDATE cp_tenants SET contact_name = ? WHERE id = ?')->execute(['Smoke Back', (int)$t1]);
        client_sync_from_tenant($pdo, (int)$t1, 0);
        t_eq('db: business -> client contact synced', 'Smoke Back', client_find($pdo, (int)$hc['id'])['contact_name']);
        $pdo->prepare("INSERT INTO cp_documents (kind, number, tenant_id, client_id, customer_name, status, issue_date, currency, total, subtotal)
            VALUES ('invoice', 'SMOKE-LEGACY', ?, NULL, 'Legacy', 'sent', ?, 'MWK', 10, 10)")->execute([(int)$t1, $today]);
        $legacy = (int)$pdo->lastInsertId();
        t_eq('db: legacy document maps to the business client', (int)$hc['id'], (int)billing_doc($pdo, $legacy)['client']['id']);
        t_ok('db: legacy document in client documents', in_array($legacy, array_map(static fn($x) => (int)$x['id'], client_documents($pdo, (int)$hc['id'])), true));
        $ext = billing_doc_save($pdo, ['id' => 0, 'kind' => 'invoice', 'client_id' => (int)$hc['id'], 'customer_name' => 'Hosted', 'issue_date' => $today,
            'currency' => 'MWK', 'extend_months' => 1], [['qty' => 1, 'item' => 'Licence', 'unit_price' => 5]], 0);
        t_eq('db: open extending invoice found', $ext, (int)(billing_open_extending_invoice($pdo, (int)$t1)['id'] ?? 0));
        billing_tenant_summary($pdo, (int)$t1);
        billing_statement($pdo, (int)$t1);
    }
    billing_email_log($pdo, null, null, 10, $cid);
} finally {
    $pdo->rollBack();
    billing_events_clear();
    billing_settings_cache(null, true);
}
t_eq('db: everything rolled back', $before, $counts());
t_done('clients_test');
