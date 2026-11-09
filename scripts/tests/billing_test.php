<?php
// Pure billing logic (no database): money maths, totals, numbering, formats, reminder days, subscription lines, validation.
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/domain.php';
require __DIR__ . '/../../app/status.php';
require __DIR__ . '/../../app/catalog.php';
require __DIR__ . '/../../app/tenants.php';
require __DIR__ . '/../../app/billing.php';

// Formats
t_eq('money owner example', 'MWK602,000.00', billing_money(602000, 'MWK'));
t_eq('money small', 'MWK0.50', billing_money(0.5, 'MWK'));
t_eq('money zero', 'MWK0.00', billing_money(0, 'MWK'));
t_eq('money tiny negative is zero', 'MWK0.00', billing_money(-0.001, 'MWK'));
t_eq('money negative', '-EUR1,234.57', billing_money(-1234.567, 'eur'));
t_eq('money millions', 'MWK1,234,567.89', billing_money(1234567.891, 'MWK'));
t_eq('date', '17/01/2026', billing_date('2026-01-17'));
t_eq('date from datetime', '09/11/2026', billing_date('2026-11-09 14:22:01'));
t_eq('date null', '', billing_date(null));
t_eq('date empty', '', billing_date(''));
t_eq('date invalid', '', billing_date('2026-02-30'));

// Line and document totals
t_eq('line total rounds', 33.33, billing_line_total(3, 11.111));
t_eq('line total float trap', 0.3, billing_line_total(3, 0.1));
$t = billing_calc_totals([
    ['qty' => 1, 'unit_price' => 100000],
    ['qty' => 2, 'unit_price' => 134000],
    ['qty' => 1, 'unit_price' => 234000],
], 0, 0);
t_eq('owner invoice subtotal', 602000.0, $t['subtotal']);
t_eq('owner invoice total', 602000.0, $t['total']);
t_eq('line totals set', [100000.0, 268000.0, 234000.0], array_column($t['items'], 'line_total'));
$t = billing_calc_totals([['qty' => 3, 'unit_price' => 0.1], ['qty' => 1, 'unit_price' => 0.2]], 0.05, 16.5);
t_eq('subtotal 0.3+0.2', 0.5, $t['subtotal']);
t_eq('tax on (sub - discount)', 0.07, $t['tax']); // (0.5 - 0.05) * 16.5% = 0.07425
t_eq('total = sub - disc + tax', 0.52, $t['total']);
$t = billing_calc_totals([['qty' => 1.5, 'unit_price' => 99999.99]], 1000, 17.5);
t_eq('qty decimal line', 149999.99, $t['items'][0]['line_total']);
t_eq('tax 17.5%', 26075.0, $t['tax']);
t_eq('total with discount and tax', 175074.99, $t['total']);
t_ok('total within tolerance', abs($t['total'] - (149999.99 - 1000 + 26075.0)) < BILLING_TOLERANCE);
t_eq('empty doc', 0.0, billing_calc_totals([], 0, 0)['total']);
t_eq('balance', 100.01, billing_balance(['total' => '200.01', 'amount_paid' => '100.00']));
t_eq('balance zero', 0.0, billing_balance(['total' => '0.30', 'amount_paid' => '0.30']));

// Numbering
t_eq('plain number', '170135', billing_number_increment('170134'));
t_eq('quote number', 'Q-1002', billing_number_increment('Q-1001'));
t_eq('keeps zero padding', 'INV-0100', billing_number_increment('INV-0099'));
t_eq('grows past width', 'Q-10000', billing_number_increment('Q-9999'));
t_eq('all zeros', 'A-001', billing_number_increment('A-000'));
t_eq('no digits', 'ABC1', billing_number_increment('ABC'));
t_eq('digits inside', '2026/INV/8', billing_number_increment('2026/INV/7'));

// Reminder days: values before the 0 are days before due, after it days after due.
t_eq('offsets default', [-3, 0, 7, 14], billing_reminder_offsets('3,0,7,14'));
t_eq('offsets no zero = after due', [7, 14], billing_reminder_offsets('7,14'));
t_eq('offsets several before', [-7, -1, 0, 3], billing_reminder_offsets('7,1,0,3'));
t_eq('offsets empty', [], billing_reminder_offsets(''));
t_eq('offsets junk ignored', [-3, 0, 7], billing_reminder_offsets('3, 0, x, 7'));
t_eq('3 days before', -3, billing_reminder_match('2026-11-12', '2026-11-09', '3,0,7,14'));
t_eq('on due date', 0, billing_reminder_match('2026-11-09', '2026-11-09', '3,0,7,14'));
t_eq('7 after', 7, billing_reminder_match('2026-11-02', '2026-11-09', '3,0,7,14'));
t_eq('14 after across month', 14, billing_reminder_match('2026-10-26', '2026-11-09', '3,0,7,14'));
t_eq('not a reminder day', null, billing_reminder_match('2026-11-10', '2026-11-09', '3,0,7,14'));
t_eq('3 after is not 3 before', null, billing_reminder_match('2026-11-06', '2026-11-09', '3,0,7,14'));
t_eq('already reminded today', null, billing_reminder_match('2026-11-09', '2026-11-09', '3,0,7,14', '2026-11-09 08:00:00'));
t_eq('reminded yesterday is fine', 0, billing_reminder_match('2026-11-09', '2026-11-09', '3,0,7,14', '2026-11-08 08:00:00'));
t_eq('days between negative', -3, billing_days_between('2026-11-12', '2026-11-09'));
t_eq('days between leap', 2, billing_days_between('2028-02-28', '2028-03-01'));

// Subscription lines
$tenant = ['plan' => 'Standard', 'business_type' => 'retail_shop', 'modules' => '["pos","stock","finance"]', 'domain' => 'shop.example.com',
    'monthly_fee' => '150000.00'];
$l = billing_subscription_lines($tenant, 3);
t_eq('one line', 1, count($l));
t_eq('sub item', 'Standard licence', $l[0]['item']);
t_eq('sub qty = months', 3.0, $l[0]['qty']);
t_eq('sub unit price = monthly fee', 150000.0, $l[0]['unit_price']);
t_eq('sub line total', 450000.0, $l[0]['line_total']);
t_ok('sub description has type label', strpos($l[0]['description'], 'Retail / Shop') !== false);
t_ok('sub description has module labels', strpos($l[0]['description'], 'Point of Sale (till), Stock & Inventory, Finance & Payments') !== false);
t_ok('sub description has months', strpos($l[0]['description'], '3 months') !== false);
$l = billing_subscription_lines(['plan' => '', 'business_type' => 'custom', 'modules' => '[]', 'monthly_fee' => '0'], 0);
t_eq('no plan gives Licence', 'Licence', $l[0]['item']);
t_eq('months at least 1', 1.0, $l[0]['qty']);
t_ok('no modules says none', strpos($l[0]['description'], 'Modules: none') !== false);

// Editable rules
t_eq('draft invoice editable', 'all', billing_editable(['kind' => 'invoice', 'status' => 'draft', 'amount_paid' => '0.00']));
t_eq('sent unpaid lines only', 'lines', billing_editable(['kind' => 'invoice', 'status' => 'sent', 'amount_paid' => '0.00']));
t_eq('sent part paid locked', 'none', billing_editable(['kind' => 'invoice', 'status' => 'sent', 'amount_paid' => '10.00']));
t_eq('paid locked', 'none', billing_editable(['kind' => 'invoice', 'status' => 'paid', 'amount_paid' => '10.00']));
t_eq('void locked', 'none', billing_editable(['kind' => 'invoice', 'status' => 'void', 'amount_paid' => '0.00']));
t_eq('quote sent editable', 'all', billing_editable(['kind' => 'quote', 'status' => 'sent']));
t_eq('quote converted locked', 'none', billing_editable(['kind' => 'quote', 'status' => 'converted']));

// Document validation
$base = ['kind' => 'invoice', 'tenant_id' => '', 'customer_name' => 'Prospect Ltd', 'issue_date' => '2026-11-09', 'due_date' => '2026-11-16',
    'currency' => 'mwk', 'discount' => '', 'tax_rate' => '', 'extend_months' => ''];
[$err, $doc, $items] = billing_doc_validate($base, [['qty' => '2', 'item' => 'Service Fee', 'description' => '', 'unit_price' => '100000'],
    ['qty' => '1', 'item' => '', 'description' => '', 'unit_price' => '']]);
t_eq('valid doc no errors', [], $err);
t_eq('blank line dropped', 1, count($items));
t_eq('currency upper', 'MWK', $doc['currency']);
t_eq('prospect tenant null', null, $doc['tenant_id']);
t_eq('validated line total', 200000.0, $items[0]['line_total']);
[$err] = billing_doc_validate($base, []);
t_ok('needs a line', in_array('Add at least one line.', $err, true));
[$err] = billing_doc_validate(array_merge($base, ['due_date' => '2026-11-01']), [['qty' => '1', 'item' => 'X', 'unit_price' => '1']]);
t_eq('due before date rejected', 1, count($err));
[$err] = billing_doc_validate(array_merge($base, ['extend_months' => '3']), [['qty' => '1', 'item' => 'X', 'unit_price' => '1']]);
t_eq('extend needs a business', 1, count($err));
[$err] = billing_doc_validate(array_merge($base, ['discount' => '5']), [['qty' => '1', 'item' => 'X', 'unit_price' => '1']]);
t_ok('discount over subtotal rejected', in_array('The discount cannot be more than the subtotal.', $err, true));
[$err] = billing_doc_validate($base, [['qty' => '0', 'item' => 'X', 'unit_price' => '1']]);
t_eq('zero qty rejected', 1, count($err));
[$err] = billing_doc_validate(array_merge($base, ['customer_name' => '', 'customer_email' => 'nope']), [['qty' => '1', 'item' => 'X', 'unit_price' => '1']]);
t_eq('name and email errors', 2, count($err));

// Settings and emails
[$err, $c] = billing_settings_validate(['invoice_next_number' => '170134', 'quote_next_number' => 'Q-1001', 'tax_rate' => '17.5',
    'reminder_days' => '3, 0, 7,14', 'brand_color' => '#d40000', 'company_name' => 'ProManaged IT', 'auto_reminders' => 'yes']);
t_eq('settings ok', [], $err);
t_eq('reminder days spaces removed', '3,0,7,14', $c['reminder_days']);
t_eq('colour upper', '#D40000', $c['brand_color']);
t_eq('auto reminders only 1', '0', $c['auto_reminders']);
[$err] = billing_settings_validate(['invoice_next_number' => 'ABC', 'reminder_days' => '3;x', 'tax_rate' => '150']);
t_eq('settings errors', 3, count($err));
t_eq('parse emails', ['a@b.com', 'c@d.org'], billing_parse_emails("a@b.com, c@d.org;A@B.com\n"));
t_eq('bad emails', ['nope'], billing_parse_emails('a@b.com nope', true));
t_eq('lines', ['Offaly,', 'Ireland'], billing_lines("Offaly,\r\n\r\nIreland\n"));
t_eq('defaults complete (Part 3 adds 6 trial and plan keys)', 26, count(billing_setting_defaults()));

t_done('billing_test');
