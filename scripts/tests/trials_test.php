<?php
// Free trials and subscription plans (BILLING.md Part 3).
// Pure tests always run (no database). The database check runs only with --db: everything it writes happens inside ONE
// transaction that is rolled back at the end, on a throw-away business it creates itself (never an existing one),
// automations are switched off, a fake mail sender is installed and nothing is ever emailed.
//   php scripts/tests/trials_test.php         pure tests
//   php scripts/tests/trials_test.php --db    pure tests + rolled-back database check
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/domain.php';
require __DIR__ . '/../../app/status.php';
require __DIR__ . '/../../app/catalog.php';
require __DIR__ . '/../../app/tenants.php';
require __DIR__ . '/../../app/billing.php'; // loads clients.php, recurring.php and trials.php
require __DIR__ . '/../../app/automation.php';
require __DIR__ . '/../../app/billing_mail.php'; // mailer + email templates

// ---------------------------------------------------------------- trial dates and status per day
$f = trial_dates('2026-11-09', 14, 0, 3);
t_eq('dates: 14 days', ['trial_started' => '2026-11-09', 'trial_until' => '2026-11-23', 'paid_until' => '2026-11-23', 'grace_days' => 0,
    'readonly_days' => 3, 'trial_converted_at' => null], $f);
t_eq('dates: across the month end', '2026-12-09', trial_dates('2026-11-09', 30, 0, 3)['trial_until']);
t_eq('dates: across the year end', '2027-01-04', trial_dates('2026-12-28', 7, 0, 3)['trial_until']);
$t = ['id' => 5, 'name' => 'Demo Lodge', 'paid_until' => '2026-11-23', 'grace_days' => 0, 'readonly_days' => 3, 'status_override' => null,
    'trial_started' => '2026-11-09', 'trial_until' => '2026-11-23', 'trial_converted_at' => null];
$perDay = [];
foreach (['2026-11-09', '2026-11-22', '2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27', '2027-01-01'] as $d) {
    $perDay[$d] = [status_compute($t, $d), trial_words($t, $d)];
}
t_eq('status and words per day, with the step-down after the end (grace 0, view-only 3)', [
    '2026-11-09' => ['active', 'Free trial – 14 days left'],
    '2026-11-22' => ['active', 'Free trial – 1 day left'],
    '2026-11-23' => ['active', 'Free trial – last day'],
    '2026-11-24' => ['read_only', 'Trial ended'],
    '2026-11-25' => ['read_only', 'Trial ended'],
    '2026-11-26' => ['read_only', 'Trial ended'],
    '2026-11-27' => ['suspended', 'Trial ended'],
    '2027-01-01' => ['suspended', 'Trial ended'],
], $perDay);
$g = ['grace_days' => 2] + $t;
t_eq('with 2 grace days', ['grace', 'grace', 'read_only', 'read_only', 'read_only', 'suspended'],
    array_map(static fn($d) => status_compute($g, $d), ['2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27', '2026-11-28', '2026-11-29']));
t_eq('step dates', ['trial_until' => '2026-11-23', 'grace_end' => '2026-11-23', 'read_only_from' => '2026-11-24', 'read_only_end' => '2026-11-26',
    'locked_from' => '2026-11-27'], trial_step_dates('2026-11-23', 0, 3));
t_eq('days left', [14, 0, -1], [trial_days_left($t, '2026-11-09'), trial_days_left($t, '2026-11-23'), trial_days_left($t, '2026-11-24')]);
t_eq('no trial: no words, no days', ['', null], [trial_words(['trial_until' => null], '2026-11-09'), trial_days_left(['trial_until' => null], '2026-11-09')]);
t_eq('converted: no words', '', trial_words(['trial_converted_at' => '2026-11-20 10:00:00'] + $t, '2026-11-21'));
t_ok('trial on', trial_is_on($t) && !trial_is_on(['trial_converted_at' => '2026-11-20 10:00:00'] + $t) && !trial_is_on([]));
t_ok('ending within 7 days', trial_ending_within($t, '2026-11-16', 7) && trial_ending_within($t, '2026-11-23', 7)
    && !trial_ending_within($t, '2026-11-15', 7) && !trial_ending_within($t, '2026-11-24', 7));

// ---------------------------------------------------------------- the form: presets and custom
t_eq('form: no trial', [0, ''], trial_days_from_form('', ''));
t_eq('form: preset 14', [14, ''], trial_days_from_form('14', ''));
t_eq('form: preset 7 and 30', [7, 30], [trial_days_from_form('7', '')[0], trial_days_from_form('30', '')[0]]);
t_eq('form: custom 45', [45, ''], trial_days_from_form('custom', '45'));
t_ok('form: unknown preset refused', trial_days_from_form('15', '')[1] !== '');
foreach (['0', '91', 'abc', '', '-3', '4.5'] as $bad) {
    t_ok('form: custom refused ' . var_export($bad, true), trial_days_from_form('custom', $bad)[0] === 0 && trial_days_from_form('custom', $bad)[1] !== '');
}
t_eq('clean days bounds', [1, 90, 0, 0], [trial_clean_days('1'), trial_clean_days(90), trial_clean_days('91'), trial_clean_days('0')]);

// ---------------------------------------------------------------- reminder days
t_eq('reminder offsets', [7, 3, 1], trial_reminder_offsets('7,3,1'));
t_eq('reminder offsets cleaned', [7, 3, 1], trial_reminder_offsets(' 1, 3 ,7,7,0,abc,100'));
t_eq('reminder offsets empty', [], trial_reminder_offsets(''));
$tenants = [
    $t, // ends 2026-11-23
    ['id' => 6, 'name' => 'Ended Inn', 'trial_until' => '2026-11-15', 'trial_converted_at' => null],
    ['id' => 7, 'name' => 'Converted Cafe', 'trial_until' => '2026-11-19', 'trial_converted_at' => '2026-11-10 09:00:00'],
    ['id' => 8, 'name' => 'No trial', 'trial_until' => null, 'trial_converted_at' => null],
    ['id' => 9, 'name' => 'Long ago', 'trial_until' => '2026-11-01', 'trial_converted_at' => null],
];
$sel = static function (string $day) use ($tenants): array {
    return array_map(static fn($x) => $x['kind'] . ':' . $x['tenant_id'] . ':' . $x['days'] . ':' . $x['dedupe'], trial_due_emails($tenants, $day, '7,3,1'));
};
t_eq('due 16 Nov: 7 days before the end; Ended Inn ended yesterday', ['trial_ending:5:7:trial_ending:5:2026-11-16', 'trial_ended:6:-1:trial_ended:6:2026-11-15'], $sel('2026-11-16'));
t_eq('due 17 Nov: nothing for Demo (6 days), Ended Inn caught up (2 days)', ['trial_ended:6:-2:trial_ended:6:2026-11-15'], $sel('2026-11-17'));
t_eq('due 18 Nov: Ended Inn caught up on day 3 at the latest', ['trial_ended:6:-3:trial_ended:6:2026-11-15'],
    array_values(array_filter($sel('2026-11-18'), static fn($s) => strpos($s, ':6:') !== false)));
t_eq('due 19 Nov: 4 days after the end is too late', [], array_values(array_filter($sel('2026-11-19'), static fn($s) => strpos($s, ':6:') !== false)));
t_eq('due 20 Nov: 3 days before', ['trial_ending:5:3:trial_ending:5:2026-11-20'], $sel('2026-11-20'));
t_eq('due 22 Nov: 1 day before', ['trial_ending:5:1:trial_ending:5:2026-11-22'], $sel('2026-11-22'));
t_eq('due 23 Nov: the last day sends nothing', [], $sel('2026-11-23'));
t_eq('due 24 Nov: ended (dedupe on the end date)', ['trial_ended:5:-1:trial_ended:5:2026-11-23'], $sel('2026-11-24'));
$ids = [];
for ($i = 0; $i < 40; $i++) {
    foreach (trial_due_emails($tenants, status_add_days('2026-10-25', $i), '7,3,1') as $x) {
        $ids[$x['tenant_id']] = true;
    }
}
t_eq('over 40 days only the businesses on a trial get emails (converted / no trial never)', [5, 6, 9], (function (array $k) {
    sort($k);
    return $k;
})(array_keys($ids)));

// ---------------------------------------------------------------- conversion rules
t_eq('start: allowed when never paid', '', trial_start_error(['trial_until' => null], false));
t_ok('start: refused while on a trial', trial_start_error($t, false) !== '');
t_ok('start: refused after any payment', trial_start_error(['trial_until' => null], true) !== '');
t_ok('start: one trial per business - a converted-but-unpaid business may not start again (owner decision)', trial_start_error(['trial_converted_at' => '2026-11-20 10:00:00'] + $t, false) !== '');
t_ok('start: refused after an ended trial too', trial_start_error(['trial_until' => '2026-10-01', 'trial_started' => '2026-09-17'], false) !== '');
t_eq('extend: allowed during the trial', '', trial_change_error($t, 'extend', '2026-11-10'));
t_eq('extend: allowed after it ended', '', trial_change_error($t, 'extend', '2026-12-01'));
t_eq('convert: allowed after it ended', '', trial_change_error($t, 'convert', '2026-12-01'));
t_ok('end: refused once ended', trial_change_error($t, 'end', '2026-11-24') !== '');
t_eq('end: allowed on the last day', '', trial_change_error($t, 'end', '2026-11-23'));
t_ok('convert: refused when converted', trial_change_error(['trial_converted_at' => '2026-11-20 10:00:00'] + $t, 'convert', '2026-11-21') !== '');
t_ok('convert: refused without a trial', trial_change_error(['trial_until' => null], 'convert', '2026-11-21') !== '');
t_eq('extend from the end', '2026-11-30', trial_extend_until('2026-11-23', '2026-11-10', 7));
t_eq('extend an ended trial from today', '2026-12-08', trial_extend_until('2026-11-23', '2026-12-01', 7));
t_eq('end now = yesterday is the last day', '2026-11-09', trial_end_now_until('2026-11-10'));
t_eq('end now: step-down starts today', 'read_only', status_compute(['paid_until' => trial_end_now_until('2026-11-10'), 'grace_days' => 0, 'readonly_days' => 3], '2026-11-10'));

// ---------------------------------------------------------------- lengthen / shorten (owner 2026-11-10: it only ever added)
$tc = ['trial_started' => '2026-11-09', 'trial_until' => '2026-11-23', 'trial_converted_at' => null]; // 14 days, today 2026-11-10
t_eq('change: add 7 from the last day', ['2026-11-30', ''], trial_change_until($tc, 'add', '7', '2026-11-10'));
t_eq('change: take 5 off', ['2026-11-18', ''], trial_change_until($tc, 'remove', '5', '2026-11-10'));
t_eq('change: take off down to yesterday (= ends today) is allowed', ['2026-11-09', ''], trial_change_until($tc, 'remove', '14', '2026-11-10'));
t_ok('change: taking off more than is left is refused', trial_change_until($tc, 'remove', '15', '2026-11-10')[1] !== '');
t_ok('change: the refusal says how many days are left', strpos(trial_change_until($tc, 'remove', '30', '2026-11-10')[1], 'Only 14 days left') === 0);
t_eq('change: 30 days in total = 30 days from the start, not added on', ['2026-12-09', ''], trial_change_until($tc, 'total', '30', '2026-11-10'));
t_eq('change: 7 days in total shortens a 14-day trial', ['2026-11-16', ''], trial_change_until($tc, 'total', '7', '2026-11-10'));
t_ok('change: total over the trial maximum refused', trial_change_until($tc, 'total', (string)(TRIAL_MAX_DAYS + 1), '2026-11-10')[1] !== '');
t_eq('change: set the last day earlier', ['2026-11-15', ''], trial_change_until($tc, 'date', '2026-11-15', '2026-11-10'));
t_eq('change: set the last day later', ['2026-12-31', ''], trial_change_until($tc, 'date', '2026-12-31', '2026-11-10'));
t_ok('change: a last day before yesterday refused', trial_change_until($tc, 'date', '2026-11-08', '2026-11-10')[1] !== '');
t_ok('change: an impossible date refused', trial_change_until($tc, 'date', '2026-02-30', '2026-11-10')[1] !== '');
t_ok('change: the same last day changes nothing', trial_change_until($tc, 'date', '2026-11-23', '2026-11-10')[1] !== '');
t_ok('change: more than a year ahead refused', trial_change_until($tc, 'add', '365', '2026-11-10')[1] !== '');
foreach (['0', '-3', '2.5', 'abc', ''] as $bad) {
    t_ok('change: bad number "' . $bad . '" refused', trial_change_until($tc, 'add', $bad, '2026-11-10')[1] !== '');
}
t_ok('change: unknown mode refused', trial_change_until($tc, 'multiply', '2', '2026-11-10')[1] !== '');
$ended = ['trial_until' => '2026-11-05'] + $tc;
t_eq('change: adding to an ended trial counts from today', ['2026-11-17', ''], trial_change_until($ended, 'add', '7', '2026-11-10'));
t_ok('change: nothing to take off an ended trial', trial_change_until($ended, 'remove', '1', '2026-11-10')[1] !== '');
t_ok('change: refused once converted', trial_change_until(['trial_converted_at' => '2026-11-20 10:00:00'] + $tc, 'add', '7', '2026-11-10')[1] !== '');
t_eq('paid until follows the trial', '2026-11-18', trial_paid_until_after('2026-11-23', '2026-11-23', '2026-11-18'));
t_eq('paid until follows a longer trial', '2026-11-30', trial_paid_until_after('2026-11-23', '2026-11-23', '2026-11-30'));
t_eq('paid until set by hand is never cut back', '2099-12-31', trial_paid_until_after('2099-12-31', '2026-11-23', '2026-11-18'));
t_eq('empty paid until takes the trial end', '2026-11-18', trial_paid_until_after('', '2026-11-23', '2026-11-18'));

// ---------------------------------------------------------------- plans and the annual discount
$monthly = ['id' => 3, 'item' => 'IT Support – Monthly', 'description' => 'Support', 'unit_price' => '100000.00', 'cost_price' => null, 'billing_interval' => 1, 'category' => 'service'];
$p = plan_lines($monthly, false, 10);
t_eq('monthly: one line, every month', [1, 1, 100000.0, 3], [$p['interval'], count($p['lines']), $p['lines'][0]['unit_price'], $p['lines'][0]['catalog_item_id']]);
$p = plan_lines($monthly, true, 10);
$tot = billing_calc_totals($p['lines'], 0, 0);
t_eq('yearly: every 12 months, 12 x price and a discount line', [12, 2, 12.0, -120000.0], [$p['interval'], count($p['lines']), $p['lines'][0]['qty'], $p['lines'][1]['unit_price']]);
t_eq('yearly: discount line words', 'Annual discount (10%)', $p['lines'][1]['item']);
t_eq('yearly: totals 1,200,000 - 10% = 1,080,000', [1200000.0, 1080000.0], [$tot['items'][0]['line_total'], $tot['total']]);
$p = plan_lines(['unit_price' => '1000'] + $monthly, true, 7.5);
t_eq('yearly 7.5% of 12,000 = 900', [-900.0, 'Annual discount (7.5%)', 11100.0], [$p['lines'][1]['unit_price'], $p['lines'][1]['item'], billing_calc_totals($p['lines'], 0, 0)['total']]);
$p = plan_lines(['unit_price' => '33333.33'] + $monthly, true, 12.5);
t_eq('yearly 12.5% of 399,999.96: discount 50,000.00 (rounded to 2 dp)', [399999.96, 349999.96], [billing_calc_totals([$p['lines'][0]], 0, 0)['total'], billing_calc_totals($p['lines'], 0, 0)['total']]);
$p0 = plan_lines($monthly, true, 0);
t_eq('yearly with no discount: a 0 discount line that follows the setting', [2, 0.0, 'annual_discount'], [count($p0['lines']), $p0['lines'][1]['unit_price'], $p0['lines'][1]['follow']]);
t_eq('yearly with no discount: the invoice leaves the 0 line out', 1, count(recurring_price_items($p0['lines'], 'MWK', [], null, 0)['items']));
t_eq('plan lines follow the plan', ['plan', ''], [plan_lines($monthly, false, 0)['lines'][0]['follow'], plan_lines(['id' => 0] + $monthly, false, 0)['lines'][0]['follow']]);
t_eq('business fee lines follow the monthly fee', 'monthly_fee', plan_lines(['id' => 0, 'follow' => 'monthly_fee'] + $monthly, true, 10)['lines'][0]['follow']);

// ---------------------------------------------------------------- owner 2026-11-10: prices follow the plan
$cat = [3 => ['id' => 3, 'item' => 'IT Support – Monthly', 'unit_price' => '120000.00', 'cost_price' => '70000.00', 'currency' => 'MWK', 'is_active' => 1]];
$yl = plan_lines($monthly, true, 10)['lines']; // made at 100,000 with 10 %
$pr = recurring_price_items($yl, 'MWK', $cat, null, 10);
t_eq('plan price raised: 12 x 120,000 less 10 % = 1,296,000', [120000.0, -144000.0, 1296000.0],
    [$pr['items'][0]['unit_price'], $pr['items'][1]['unit_price'], billing_calc_totals($pr['items'], 0, 0)['total']]);
t_eq('the new cost follows too, and the discount cost is known', [70000.0, 0.0], [$pr['items'][0]['cost_price'], $pr['items'][1]['cost_price']]);
$pr = recurring_price_items($yl, 'MWK', $cat, null, 15);
t_eq('discount setting changed to 15 %: label and amount follow', ['Annual discount (15%)', -216000.0], [$pr['items'][1]['item'], $pr['items'][1]['unit_price']]);
$pr = recurring_price_items($yl, 'MWK', $cat, null, 0);
t_eq('discount set to 0: the line is left out', 1, count($pr['items']));
$pr = recurring_price_items($yl, 'MWK', [3 => ['is_active' => 0] + $cat[3]], null, 10);
t_eq('plan switched off: the price last stored is kept', 100000.0, $pr['items'][0]['unit_price']);
t_eq('plan deleted: the price last stored is kept', 100000.0, recurring_price_items($yl, 'MWK', [], null, 10)['items'][0]['unit_price']);
$pr = recurring_price_items($yl, 'MWK', [3 => ['currency' => 'USD'] + $cat[3]], null, 10);
t_ok('plan priced in another currency: refused, never billed as MWK', $pr['error'] !== '' && strpos($pr['error'], 'USD') !== false);
$fixed = [['qty' => 1.0, 'item' => 'Hosting', 'unit_price' => 5000.0, 'cost_price' => null, 'catalog_item_id' => 3, 'follow' => '']];
t_eq('a fixed line keeps its price even from a saved item', 5000.0, recurring_price_items($fixed, 'MWK', $cat, null, 10)['items'][0]['unit_price']);
$feeLines = plan_lines(['id' => 0, 'follow' => 'monthly_fee', 'unit_price' => '50000'] + $monthly, false, 0)['lines'];
$pr = recurring_price_items($feeLines, 'MWK', [], ['monthly_fee' => '65000.00', 'currency' => 'MWK'], 0);
t_eq('monthly fee raised on the business: followed', 65000.0, $pr['items'][0]['unit_price']);
t_ok('monthly fee in another currency: refused', recurring_price_items($feeLines, 'MWK', [], ['monthly_fee' => '65', 'currency' => 'USD'], 0)['error'] !== '');
$withFollow = array_map(static fn($l) => ['qty' => (string)$l['qty'], 'item' => $l['item'], 'description' => $l['description'],
    'unit_price' => number_format((float)$l['unit_price'], 2, '.', ''), 'cost_price' => '', 'catalog_item_id' => (string)($l['catalog_item_id'] ?? ''), 'follow' => $l['follow']], $yl);
[$ev, $rv] = recurring_validate(['client_id' => '1', 'title' => 'X', 'interval_months' => '12', 'next_date' => '2026-11-09', 'currency' => 'MWK', 'send_mode' => 'draft'], $withFollow);
t_eq('schedule keeps where each price comes from', [[], 'plan', 'annual_discount'], [$ev, $rv['items'][0]['follow'], $rv['items'][1]['follow']]);
[, $rv] = recurring_validate(['client_id' => '1', 'title' => 'X', 'interval_months' => '1', 'next_date' => '2026-11-09', 'currency' => 'MWK'],
    [['qty' => '1', 'item' => 'Typed', 'unit_price' => '10', 'follow' => 'plan']]);
t_eq('"follows the Catalog" without a saved item: fixed', '', $rv['items'][0]['follow']);
[$ev] = recurring_validate(['client_id' => '1', 'title' => 'X', 'interval_months' => '1', 'next_date' => '2026-11-09', 'currency' => 'MWK'],
    [['qty' => '1', 'item' => 'Typed', 'unit_price' => '100'], ['qty' => '1', 'item' => 'Disc', 'unit_price' => '-10', 'follow' => 'annual_discount']]);
t_ok('a discount line with nothing to follow is refused', (bool)array_filter($ev, static fn($m) => strpos($m, 'annual discount') !== false));
[$ev, $rv] = recurring_validate(['client_id' => '1', 'title' => 'X', 'interval_months' => '1', 'next_date' => '2026-11-09', 'currency' => 'MWK'],
    [['qty' => '1', 'item' => 'Typed', 'unit_price' => '10', 'follow' => 'nonsense']]);
t_eq('an unknown price source is fixed', '', $rv['items'][0]['follow']);
[, $di] = billing_lines_validate([['qty' => '1', 'item' => 'A', 'unit_price' => '1']]);
t_ok('invoice lines carry no price source', !array_key_exists('follow', $di[0]));

// ---------------------------------------------------------------- owner 2026-11-10: a month end stays a month end
t_eq('28 Feb + 1 = 31 Mar', '2026-03-31', status_add_months('2026-02-28', 1));
t_eq('30 Apr + 1 = 31 May', '2026-05-31', status_add_months('2026-04-30', 1));
t_eq('28 Feb in a leap year is not the end: 28 Mar', '2028-03-28', status_add_months('2028-02-28', 1));
t_eq('29 Feb 2028 + 1 = 31 Mar', '2028-03-31', status_add_months('2028-02-29', 1));
t_eq('30 Jan + 1 = 28 Feb (clamped)', '2026-02-28', status_add_months('2026-01-30', 1));
t_eq('31 Jan, 28 Feb, 31 Mar: no drift', ['2026-02-28', '2026-03-31', '2026-04-30'],
    [status_add_months('2026-01-31', 1), status_add_months(status_add_months('2026-01-31', 1), 1), status_add_months(status_add_months(status_add_months('2026-01-31', 1), 1), 1)]);
t_eq('back a month from a month end', '2026-02-28', status_add_months('2026-03-31', -1));
t_eq('15th stays 15th', '2026-03-15', status_add_months('2026-02-15', 1));
t_eq('anchor day: set up on the 30th', [30, 31, 31, 15], [recurring_anchor_day('2026-01-30'), recurring_anchor_day('2026-01-31'), recurring_anchor_day('2026-02-28'), recurring_anchor_day('2026-02-15')]);
t_eq('a schedule on the 30th: 28 Feb, then back to 30 Mar', ['2026-02-28', '2026-03-30'],
    [recurring_add_months('2026-01-30', 1, 30), recurring_add_months('2026-02-28', 1, 30)]);
t_eq('a month-end schedule: 30 Apr, 31 May', ['2026-04-30', '2026-05-31'], [recurring_add_months('2026-03-31', 1, 31), recurring_add_months('2026-04-30', 1, 31)]);

// ---------------------------------------------------------------- owner 2026-11-10: free time only on a trial
[$ez] = billing_doc_validate(['kind' => 'invoice', 'customer_name' => 'X', 'issue_date' => '2026-11-10', 'currency' => 'MWK', 'extend_months' => '1'],
    [['qty' => '1', 'item' => 'Licence', 'unit_price' => '0']]);
t_ok('a free invoice that extends a licence is refused', (bool)array_filter($ez, static fn($m) => strpos($m, 'must cost something') !== false));
[$ez] = billing_doc_validate(['kind' => 'invoice', 'customer_name' => 'X', 'issue_date' => '2026-11-10', 'currency' => 'MWK', 'extend_months' => '0'],
    [['qty' => '1', 'item' => 'Free setup', 'unit_price' => '0']]);
t_eq('a free invoice that extends nothing is fine', [], $ez);

// ---------------------------------------------------------------- owner 2026-11-10: void and refund
$pay = ['id' => 7, 'amount' => '100000.00', 'currency' => 'MWK', 'voided_at' => null, 'refund_of' => null];
$ref1 = ['id' => 8, 'amount' => '-30000.00', 'currency' => 'MWK', 'voided_at' => null, 'refund_of' => 7];
t_eq('refundable: all of it', 100000.0, billing_payment_refundable($pay, [$pay]));
t_eq('refundable after a 30,000 refund', 70000.0, billing_payment_refundable($pay, [$pay, $ref1]));
t_eq('a void refund gives nothing back', 100000.0, billing_payment_refundable($pay, [$pay, ['voided_at' => '2026-11-10 10:00:00'] + $ref1]));
t_eq('nothing to refund on a void payment or a refund', [0.0, 0.0], [billing_payment_refundable(['voided_at' => '2026-11-10'] + $pay, []), billing_payment_refundable($ref1, [])]);
t_eq('a payment can be voided', '', billing_void_payment_error($pay, [$pay]));
t_ok('not twice', billing_void_payment_error(['voided_at' => '2026-11-10'] + $pay, []) !== '');
t_ok('not once partly refunded', strpos(billing_void_payment_error($pay, [$pay, $ref1]), 'refunded') !== false);
t_ok('a refund cannot be voided', billing_void_payment_error($ref1, []) !== '');
t_eq('state words', ['', 'Partly refunded (MWK30,000.00)', 'Refund of payment #7', 'Void: entered twice'],
    [billing_payment_state($pay, [$pay]), billing_payment_state($pay, [$pay, $ref1]), billing_payment_state($ref1, []),
     billing_payment_state(['voided_at' => '2026-11-10', 'void_reason' => 'entered twice'] + $pay, [])]);
t_eq('refunded in full', 'Refunded in full', billing_payment_state($pay, [$pay, ['amount' => '-100000.00'] + $ref1]));
t_eq('licence back: nothing moved since, exactly back', '2026-11-30', billing_licence_back_until('2026-12-31', '2026-11-30', '2026-12-31', 1)[0]);
t_eq('licence back: a later payment moved it on, months off now', '2027-01-31', billing_licence_back_until('2027-02-28', '2026-11-30', '2026-12-31', 1)[0]);
t_eq('licence back: never before where it started', '2026-11-30', billing_licence_back_until('2027-01-15', '2026-11-30', '2026-12-31', 3)[0]);
$lb = billing_licence_back_until('2026-12-10', '2026-11-30', '2026-12-31', 1);
t_ok('licence back: already moved back by hand, left as it is', $lb[0] === '2026-12-10' && strpos($lb[1], 'by hand') !== false);
$stm = billing_statement_build([['id' => 1, 'number' => 'INV-1', 'issue_date' => '2026-11-01', 'total' => '100000.00']],
    [['id' => 7, 'document_id' => 1, 'created_at' => '2026-11-02 10:00:00', 'reference' => 'T1', 'amount' => '100000.00', 'method' => 'bank_transfer', 'voided_at' => null],
     ['id' => 8, 'document_id' => 1, 'created_at' => '2026-11-03 10:00:00', 'reference' => 'R1', 'amount' => '-30000.00', 'method' => 'bank_transfer', 'voided_at' => null],
     ['id' => 9, 'document_id' => 1, 'created_at' => '2026-11-04 10:00:00', 'reference' => 'X', 'amount' => '5000.00', 'method' => 'cash', 'voided_at' => '2026-11-04 11:00:00']],
    null, '2026-11-30');
t_eq('statement: a refund is a debit, a void payment is left out', [3, 'refund', 30000.0, 30000.0],
    [count($stm['lines']), $stm['lines'][2]['type'], $stm['lines'][2]['debit'], $stm['closing']]);

// ---------------------------------------------------------------- payment audit 2026-11-10
$costed = ['cost_price' => '60000.00'] + $monthly;
$p = plan_lines($costed, true, 10);
t_eq('annual discount line is a known zero cost (profit counts it)', 0.0, $p['lines'][1]['cost_price']);
t_eq('yearly profit: 1,080,000 - 720,000 = 360,000', 360000.0, billing_profit(billing_calc_totals($p['lines'], 0, 0)['items'])['profit']);
$pu = plan_lines($monthly, true, 10); // no cost price on the plan
$bp = billing_profit(billing_calc_totals($pu['lines'], 0, 0)['items']);
t_eq('uncosted plan billed yearly: discount line stays unknown too, so no negative profit', [null, false, 0.0], [$pu['lines'][1]['cost_price'], $bp['complete'], $bp['profit']]);
t_eq('a priced plan can be billed', '', plan_price_problem($monthly));
t_ok('a plan at 0 cannot be billed (it would give the licence away)', plan_price_problem(['unit_price' => '0.00'] + $monthly) !== '');
t_ok('a plan at 0.004 counts as 0', plan_price_problem(['unit_price' => '0.004'] + $monthly) !== '');
$rIn = ['client_id' => '1', 'title' => 'Licence', 'interval_months' => '1', 'next_date' => '2026-11-10', 'end_date' => '', 'currency' => 'MWK',
    'due_days' => '', 'extend_months' => '1', 'send_mode' => 'auto', 'notes' => ''];
$zero = [['qty' => '1', 'item' => 'PMS licence | Monthly', 'unit_price' => '0.00']];
[$e1] = recurring_validate($rIn, $zero);
t_ok('schedule: a 0 total that extends a licence is refused', (bool)array_filter($e1, static fn($m) => strpos($m, 'must cost something') !== false));
[$e2] = recurring_validate(['extend_months' => '0'] + $rIn, $zero);
t_ok('schedule: a 0 total that extends nothing is allowed', !array_filter($e2, static fn($m) => strpos($m, 'must cost something') !== false));
[$e3, $r3] = recurring_validate($rIn, [['qty' => '1', 'item' => 'Licence', 'unit_price' => '75000']], 'MWK', '14');
t_eq('schedule: priced, due days from the setting', [[], 14], [$e3, $r3['due_days']]);
t_eq('amounts that fit', '', billing_amount_problem(billing_calc_totals([['qty' => 1, 'unit_price' => 9999999999.99]], 0, 0)));
t_ok('a line above the payment column limit is refused', billing_amount_problem(billing_calc_totals([['qty' => 2, 'unit_price' => 9999999999.99]], 0, 0)) !== '');
t_ok('tax pushing the total over the limit is refused', billing_amount_problem(billing_calc_totals([['qty' => 1, 'unit_price' => 9999999999.99]], 0, 16.5)) !== '');
[$e4] = billing_doc_validate(['kind' => 'invoice', 'customer_name' => 'X', 'issue_date' => '2026-11-10', 'due_date' => '2026-11-17', 'currency' => 'MWK',
    'discount' => '0', 'tax_rate' => '0', 'extend_months' => '0'], [['qty' => '1000', 'item' => 'Big', 'unit_price' => '99999999999']]);
t_ok('invoice editor refuses an amount the payments table cannot hold', (bool)array_filter($e4, static fn($m) => strpos($m, 'too large') !== false));
t_eq('a quarterly plan is never turned into yearly', [3, 1, 1.0], (function ($p) {
    return [$p['interval'], count($p['lines']), $p['lines'][0]['qty']];
})(plan_lines(['billing_interval' => 3] + $monthly, true, 10)));
t_eq('discount setting', [0.0, 12.5, 0.0, 0.0], [plan_discount_pct('0'), plan_discount_pct('12.5'), plan_discount_pct('abc'), plan_discount_pct('100')]);
t_ok('discount lines are valid recurring lines (negative price allowed, total not below 0)', (function () use ($monthly) {
    $lines = array_map(static fn($l) => ['qty' => (string)$l['qty'], 'item' => $l['item'], 'description' => $l['description'],
        'unit_price' => number_format((float)$l['unit_price'], 2, '.', ''), 'cost_price' => '', 'catalog_item_id' => ''], plan_lines($monthly, true, 10)['lines']);
    [$err] = recurring_validate(['client_id' => '1', 'title' => 'X', 'interval_months' => '12', 'next_date' => '2026-11-09', 'currency' => 'MWK', 'send_mode' => 'draft'], $lines);
    return $err === [];
})());
t_eq('licence plan of a hosted business extends by the interval', 12, plan_extend_months(['category' => 'licence'], 4, 12));
t_eq('a service plan never extends a licence', 0, plan_extend_months(['category' => 'service'], 4, 1));
t_eq('a licence plan without a business cannot extend', 0, plan_extend_months(['category' => 'licence'], null, 1));
t_eq('interval words', ['every month', 'every 3 months', 'every year'], [plan_interval_words(1), plan_interval_words(3), plan_interval_words(12)]);
[$err, $c] = billing_catalog_validate(['item' => 'Plan', 'unit_price' => '10', 'currency' => 'MWK', 'billing_interval' => '12']);
t_eq('saved item marked as a yearly plan', [[], 12], [$err, $c['billing_interval']]);
[$err, $c] = billing_catalog_validate(['item' => 'One-off', 'unit_price' => '10', 'currency' => 'MWK', 'billing_interval' => '']);
t_eq('saved item not a plan', [[], null], [$err, $c['billing_interval']]);
t_ok('plan interval 2 refused', billing_catalog_validate(['item' => 'P', 'unit_price' => '1', 'currency' => 'MWK', 'billing_interval' => '2'])[0] !== []);
t_ok('no interval field: left alone', !array_key_exists('billing_interval', billing_catalog_validate(['item' => 'P', 'unit_price' => '1', 'currency' => 'MWK'])[1]));

// ---------------------------------------------------------------- settings
$d = billing_setting_defaults();
t_eq('settings defaults', ['0', '3', '7,3,1', '14', '14', '0'], [$d['trial_grace_days'], $d['trial_readonly_days'], $d['trial_reminder_days'],
    $d['default_grace_days'], $d['default_readonly_days'], $d['annual_discount_pct']]);
t_eq('settings valid', [], billing_settings_validate(['trial_grace_days' => '0', 'trial_readonly_days' => '3', 'trial_reminder_days' => '7, 3, 1',
    'default_grace_days' => '14', 'default_readonly_days' => '14', 'annual_discount_pct' => '12.5'])[0]);
t_eq('settings: spaces removed from reminder days', '7,3,1', billing_settings_validate(['trial_reminder_days' => '7, 3, 1'])[1]['trial_reminder_days']);
t_eq('settings invalid', 4, count(billing_settings_validate(['trial_grace_days' => '-1', 'trial_reminder_days' => '7,0', 'default_readonly_days' => '400',
    'annual_discount_pct' => '100'])[0]));
t_ok('settings: reminder day over 90 refused', billing_settings_validate(['trial_reminder_days' => '91'])[0] !== []);

// ---------------------------------------------------------------- reports
$rt = [
    ['trial_started' => '2026-11-02', 'trial_until' => '2026-11-16', 'trial_converted_at' => '2026-11-10 09:00:00'], // started + converted
    ['trial_started' => '2026-11-01', 'trial_until' => '2026-11-08', 'trial_converted_at' => null],                  // started + lost
    ['trial_started' => '2026-10-20', 'trial_until' => '2026-11-03', 'trial_converted_at' => null],                  // lost (ended in Nov)
    ['trial_started' => '2026-11-05', 'trial_until' => '2026-11-19', 'trial_converted_at' => null],                  // started, still on
    ['trial_started' => '2026-10-01', 'trial_until' => '2026-10-15', 'trial_converted_at' => '2026-10-10 09:00:00'], // outside the period
];
t_eq('trial figures for November (today 9 Nov)', ['started' => 3, 'converted' => 1, 'lost' => 2, 'rate' => 33.3, 'active' => 1],
    reports_trials($rt, '2026-11-01', '2026-11-30', '2026-11-09'));
t_eq('no figures: rate unknown', ['started' => 0, 'converted' => 0, 'lost' => 0, 'rate' => null, 'active' => 0], reports_trials([], '2026-11-01', '2026-11-30', '2026-11-09'));
t_eq('all converted: 100%', 100.0, reports_trials([$rt[0]], '2026-11-01', '2026-11-30', '2026-11-30')['rate']);

// ---------------------------------------------------------------- automations: keys, events, the daily step
t_eq('defaults: trial emails auto', ['auto', 'auto', 'auto', 'auto'], array_values(array_intersect_key(automation_defaults(),
    array_flip(['auto_trial_started', 'auto_trial_ending', 'auto_trial_ended', 'auto_trial_converted']))));
t_eq('key trial_ending per business + day', 'trial_ending:5:2026-11-16', automation_dedupe_key('trial_ending', 5, '2026-11-16'));
t_ok('3-day and 7-day reminders differ', automation_dedupe_key('trial_ending', 5, '2026-11-20') !== automation_dedupe_key('trial_ending', 5, '2026-11-16'));
$plan = automation_plan_event('trial_started', ['tenant_id' => 5, 'day' => '2026-11-09'], ['auto_trial_started' => 'approve']);
t_eq('event trial_started', [['kind' => 'trial_started', 'ref_id' => 5, 'mode' => 'approve', 'reason' => 'Free trial started', 'dedupe' => 'trial_started:5:2026-11-09']], $plan);
t_eq('event trial_converted (mode off when not set)', 'off', automation_plan_event('trial_converted', ['tenant_id' => 5, 'day' => '2026-11-20'], [])[0]['mode']);
t_eq('event without a business does nothing', [], automation_plan_event('trial_started', [], ['auto_trial_started' => 'auto']));

class TrialsTestPdo extends PDO
{
    public function __construct()
    {
    }
}
$fakePdo = new TrialsTestPdo();
$log = [];
$deps = [
    'lock' => function () use (&$log) {
        $log[] = 'lock';
        return 'L';
    },
    'unlock' => function () use (&$log) {
        $log[] = 'unlock';
    },
    'modes' => function () {
        return ['auto_reminders' => 'off', 'auto_trial_ending' => 'auto', 'auto_trial_ended' => 'approve'];
    },
    'trials_due' => function ($pdo, $today) use ($tenants) {
        return trial_due_emails($tenants, $today, '7,3,1');
    },
    'dispatch' => function ($pdo, $kind, $ref, $opts, $reason, $dedupe, $mode) use (&$log) {
        $log[] = $kind . ':' . $ref . ':' . $mode . ':' . $dedupe . ':' . ($opts['today'] ?? '');
        return ['result' => $mode === 'auto' ? 'sent' : 'pending', 'outbox_id' => 1, 'error' => ''];
    },
    'exists' => function ($pdo, $key) use (&$log) {
        $log[] = 'exists:' . $key;
        return $key === 'trial_ended:6:2026-11-15';
    },
    'steps' => ['reminders', 'trials'],
];
$r = billing_daily($fakePdo, '2026-11-16', false, $deps);
t_eq('daily: trial emails per their own modes', ['lock', 'trial_ending:5:auto:trial_ending:5:2026-11-16:2026-11-16', 'trial_ended:6:approve:trial_ended:6:2026-11-15:2026-11-16', 'unlock'], $log);
t_eq('daily: counted', [1, 1], [$r['counts']['sent'], $r['counts']['queued']]);
t_ok('daily: listed', count($r['trials']) === 2 && strpos($r['trials'][0]['label'], 'Demo Lodge (free trial ends in 7 days)') === 0);
$log = [];
$r = billing_daily($fakePdo, '2026-11-16', true, $deps);
t_eq('dry run: only reads', ['exists:trial_ending:5:2026-11-16', 'exists:trial_ended:6:2026-11-15'], $log);
t_eq('dry run: results', ['would_send', 'duplicate'], array_column($r['trials'], 'result'));
$log = [];
$r = billing_daily($fakePdo, '2026-11-16', false, ['modes' => function () {
    return ['auto_reminders' => 'off'];
}, 'trials_due' => function () use (&$log) {
    $log[] = 'read';
    return [];
}] + $deps);
t_ok('daily: trial emails off = not even read, no note', !in_array('read', $log, true) && $r['notes'] === ['Automatic reminders are off.']);

// ---------------------------------------------------------------- the emails (pure render)
$company = email_company_defaults();
$company['payment_details'] = "MWK Account\nStandard Bank";
$company['phone'] = '+265 999 000 111';
$base = ['tenant' => ['name' => 'Demo Lodge', 'contact_name' => 'Mary'], 'type' => 'Full hotel', 'modules' => ['Rooms', 'Restaurant'],
    'trial' => ['started' => '2026-11-09', 'until' => '2026-11-23', 'days_left' => 7, 'length' => 14, 'read_only_from' => '2026-11-24', 'locked_from' => '2026-11-27'],
    'offer' => [['Monthly', 'MWK100,000.00 a month'], ['Yearly', 'MWK1,080,000.00 a year (10% off)']],
    'plan' => ['title' => 'PMS licence – Monthly', 'every' => 'every month', 'amount' => 'MWK100,000.00', 'first_invoice' => '170140']];
$m = email_render('trial_started', $base, $company);
t_ok('started: subject, length, modules, who to call', $m['subject'] === 'Your free trial has started: it runs until 23/11/2026'
    && strpos($m['text'], '14 days') !== false && strpos($m['text'], 'Rooms, Restaurant') !== false && strpos($m['text'], '+265 999 000 111') !== false);
$m = email_render('trial_ending', $base, $company);
t_ok('ending: subject, offer, no payment details', $m['subject'] === 'Your free trial ends in 7 days' && strpos($m['html'], 'MWK1,080,000.00 a year (10% off)') !== false
    && strpos($m['html'], 'Standard Bank') === false && strpos($m['text'], 'view-only from 24/11/2026') !== false);
t_eq('ending: last day subject', 'Your free trial ends today', email_render('trial_ending', ['trial' => ['days_left' => 0] + $base['trial']] + $base, $company)['subject']);
$m = email_render('trial_ended', $base, $company);
t_ok('ended: what now, how to continue, payment details', $m['subject'] === 'Your free trial has ended' && strpos($m['html'], 'Standard Bank') !== false
    && strpos($m['text'], 'never deleted') !== false && strpos($m['text'], 'until 26/11/2026') !== false && strpos($m['text'], 'pauses') !== false);
$m = email_render('trial_converted', $base, $company);
t_ok('converted: thanks, plan, first invoice', $m['subject'] === 'Thank you for choosing ProManaged IT' && strpos($m['text'], 'PMS licence – Monthly') !== false
    && strpos($m['text'], '170140') !== false && strpos($m['html'], 'Standard Bank') === false);
t_ok('trial kinds can be sent', !array_diff(automation_trial_kinds(), billing_mail_kinds()) && !array_diff(automation_trial_kinds(), email_kinds()));

if (!in_array('--db', $argv, true)) {
    t_done('trials_test');
}

// =====================================================================================================================
// Database check: ONE transaction, rolled back, on a throw-away business. No email: automations off, fake sender.
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
$sent = [];
billing_mail_sender(function (array $m) use (&$sent) {
    $sent[] = $m['subject'] ?? '';
    return ['ok' => false, 'error' => 'test: never sent', 'to' => []];
});
$pdo = db();
$fingerprint = static function () use ($pdo): array {
    $o = [];
    foreach (['cp_tenants', 'cp_clients', 'cp_documents', 'cp_document_items', 'cp_payments', 'cp_recurring', 'cp_catalog_items', 'cp_audit_log', 'cp_outbox', 'cp_email_log', 'cp_settings'] as $t) {
        $o[$t] = (int)$pdo->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn();
    }
    $o['tenants'] = $pdo->query('SELECT GROUP_CONCAT(CONCAT_WS(":", id, paid_until, IFNULL(trial_until, "-"), IFNULL(trial_started, "-"), IFNULL(trial_converted_at, "-"),
        grace_days, readonly_days, IFNULL(status_override, "-")) ORDER BY id) FROM cp_tenants')->fetchColumn();
    $o['settings'] = $pdo->query('SELECT GROUP_CONCAT(CONCAT(setting_key, "=", setting_value) ORDER BY setting_key) FROM cp_settings')->fetchColumn();
    $o['catalog'] = $pdo->query('SELECT GROUP_CONCAT(CONCAT_WS(":", id, unit_price, IFNULL(billing_interval, "-"), is_active) ORDER BY id) FROM cp_catalog_items')->fetchColumn();
    return $o;
};
$before = $fingerprint();
$today = status_today();
$pdo->beginTransaction();
try {
    billing_settings_cache(null, true);
    // A throw-away business (never tenant 1 or 2: those are the live hotels' licences).
    $slug = 'trials-test-' . bin2hex(random_bytes(3));
    $pdo->prepare("INSERT INTO cp_tenants (slug, name, business_type, modules, domain, plan, monthly_fee, currency, paid_until, grace_days, readonly_days, billing_email)
        VALUES (?, 'Trials Test Lodge', 'full_hotel', ?, ?, 'Test', '100000.00', 'MWK', ?, 14, 14, 'trials@example.invalid')")
        ->execute([$slug, json_encode(['pos']), $slug . '.example.invalid', $today]);
    $tid = (int)$pdo->lastInsertId();
    t_ok('db: throw-away business is new (not a live hotel)', $tid > 2);
    $cid = client_sync_from_tenant($pdo, $tid, 0);
    t_ok('db: its client', $cid > 0);
    t_ok('db: never paid', !trial_has_paid($pdo, $tid));

    // Start, refuse a second start, extend.
    t_eq('db: trial started', '', trial_start($pdo, $tid, 14, 0, $today));
    $row = tenant_find($pdo, $tid);
    t_eq('db: trial dates and step-down', [$today, status_add_days($today, 14), status_add_days($today, 14), 0, 3, null],
        [$row['trial_started'], $row['trial_until'], $row['paid_until'], (int)$row['grace_days'], (int)$row['readonly_days'], $row['trial_converted_at']]);
    t_eq('db: trial_started event queued (fires after the commit)', ['trial_started', $tid], [billing_events_queue()[0][0] ?? '', (int)(billing_events_queue()[0][1]['tenant_id'] ?? 0)]);
    billing_events_clear();
    t_ok('db: second start refused', trial_start($pdo, $tid, 7, 0, $today) !== '');
    t_eq('db: extended by 7', '', trial_extend($pdo, $tid, 7, 0, $today));
    t_eq('db: new end', status_add_days($today, 21), tenant_find($pdo, $tid)['trial_until']);
    $sh = trial_change($pdo, $tid, 'remove', '7', 0, $today);
    t_eq('db: shortened by 7', ['', status_add_days($today, 14)], [$sh['error'], tenant_find($pdo, $tid)['trial_until']]);
    t_eq('db: paid until followed it', status_add_days($today, 14), tenant_find($pdo, $tid)['paid_until']);
    t_eq('db: set 21 days in total again', '', trial_change($pdo, $tid, 'total', '21', 0, $today)['error']);
    t_ok('db: audited', (int)$pdo->query("SELECT COUNT(*) FROM cp_audit_log WHERE tenant_id = " . $tid . " AND action IN ('trial_started','trial_extended','trial_shortened')")->fetchColumn() === 4);

    // The licence payload fields, as api/licence.php builds them.
    $row = tenant_find($pdo, $tid);
    t_eq('db: licence answer trial fields', [true, status_add_days($today, 21), 'active'],
        [!empty($row['trial_until']) && empty($row['trial_converted_at']), $row['trial_until'], status_compute($row, $today)]);

    // Reminder 7 days before the end: selected, built, queued once (approve), refs point to the client.
    $day14 = status_add_days($today, 14);
    $due = array_values(array_filter(trials_due($pdo, $day14), static fn($x) => $x['tenant_id'] === $tid));
    t_eq('db: trial_ending due 7 days before', ['trial_ending', 7], [$due[0]['kind'] ?? '', $due[0]['days'] ?? 0]);
    $b = billing_mail_build($pdo, 'trial_ending', $tid, ['today' => $day14], false);
    t_ok('db: ending email built for the business contact', $b['ok'] && $b['subject'] === 'Your free trial ends in 7 days' && $b['to'] === ['trials@example.invalid'] && $b['client_id'] === $cid, json_encode([$b['error'], $b['to']]));
    t_eq('db: outbox refs = the client, no document', [$cid, null], outbox_resolve_refs($pdo, 'trial_ending', $tid));
    $x1 = automation_dispatch($pdo, 'trial_ending', $tid, ['today' => $day14], 'test', $due[0]['dedupe'], 'approve');
    $x2 = automation_dispatch($pdo, 'trial_ending', $tid, ['today' => $day14], 'test', $due[0]['dedupe'], 'approve');
    t_eq('db: queued once, then a duplicate', ['pending', 'duplicate'], [$x1['result'], $x2['result']]);
    $ob = outbox_find($pdo, (int)$x1['outbox_id']);
    t_eq('db: outbox row', ['trial_ending', $tid, $cid, null], [$ob['kind'], (int)$ob['ref_id'], (int)$ob['client_id'], $ob['document_id']]);
    t_ok('db: outbox preview works', outbox_preview($pdo, $ob)['ok']);
    $dry = billing_daily($pdo, $day14, true, ['modes' => function () {
        return ['auto_reminders' => 'off', 'auto_trial_ending' => 'approve', 'auto_trial_ended' => 'approve'];
    }, 'steps' => ['trials']]);
    t_ok('db: dry run sees it as already handled', in_array('duplicate', array_column(array_values(array_filter($dry['trials'], static fn($i) => strpos($i['label'], 'Trials Test Lodge') === 0)), 'result'), true));
    t_ok('db: ended email refused while the trial runs', !billing_mail_build($pdo, 'trial_ended', $tid, ['today' => $today], false)['ok']);

    // End now: step-down starts today; the ended email is due tomorrow.
    t_eq('db: ended now', '', trial_end_now($pdo, $tid, 0, $today));
    $row = tenant_find($pdo, $tid);
    t_eq('db: last day yesterday, view-only today', [status_add_days($today, -1), 'read_only', 'Trial ended'], [$row['trial_until'], status_compute($row, $today), trial_words($row, $today)]);
    $b = billing_mail_build($pdo, 'trial_ended', $tid, ['today' => $today], false);
    t_ok('db: ended email with payment details', $b['ok'] && strpos($b['text'], 'HOW TO PAY') !== false);
    t_ok('db: ending email refused after the end', !billing_mail_build($pdo, 'trial_ending', $tid, ['today' => $today], false)['ok']);

    // Plans: seeded at price 0 only when none exist.
    $hadPlans = (int)$pdo->query('SELECT COUNT(*) FROM cp_catalog_items WHERE billing_interval IS NOT NULL')->fetchColumn();
    billing_seed_plans($pdo, 0);
    $plans = plan_list($pdo, false);
    if ($hadPlans === 0) {
        t_eq('db: seeded plans', ['PMS licence – Monthly', 'IT Support – Monthly', 'Website hosting – Monthly', 'Website maintenance – Monthly', 'PMS licence – Yearly'],
            array_column($plans, 'item'));
        t_ok('db: seeded at price 0', !array_filter($plans, static fn($p) => (float)$p['unit_price'] !== 0.0));
    } else {
        t_eq('db: plans exist already, nothing seeded', $hadPlans, count($plans));
    }
    billing_seed_plans($pdo, 0);
    t_eq('db: seeding runs once', count($plans), count(plan_list($pdo, false)));
    $planId = billing_catalog_save($pdo, 0, ['category' => 'licence', 'sku' => '', 'item' => 'Test licence – Monthly', 'description' => '', 'unit_price' => '50000',
        'unit' => 'month', 'cost_price' => '', 'currency' => 'MWK', 'is_active' => 1, 'sort' => 0, 'billing_interval' => 1], 0);
    t_eq('db: plan saved with its interval', 1, (int)plan_find($pdo, $planId)['billing_interval']);
    billing_catalog_save($pdo, $planId, ['category' => 'licence', 'sku' => '', 'item' => 'Test licence – Monthly', 'description' => '', 'unit_price' => '50000',
        'unit' => 'month', 'cost_price' => '', 'currency' => 'MWK', 'is_active' => 1, 'sort' => 0], 0);
    t_ok('db: a save without the field keeps the interval', plan_find($pdo, $planId) !== null);
    billing_settings_save($pdo, ['annual_discount_pct' => '10'], 0);

    // Convert (yearly, 10% off): schedule + draft first invoice, normal days back, paid_until unchanged until paid.
    $paidBefore = tenant_find($pdo, $tid)['paid_until'];
    $cv = trial_convert($pdo, $tid, ['plan_id' => $planId, 'yearly' => true, 'send_mode' => 'draft'], 0, $today);
    t_ok('db: converted', $cv['ok'] && $cv['schedule_id'] > 0 && $cv['document_id'] > 0, $cv['error']);
    $sch = recurring_find($pdo, $cv['schedule_id']);
    $inv = billing_doc($pdo, $cv['document_id']);
    t_eq('db: yearly schedule for the business, extends 12 months', [12, $cid, $tid, 12, recurring_add_months($today, 12)],
        [(int)$sch['interval_months'], (int)$sch['client_id'], (int)$sch['tenant_id'], (int)$sch['extend_months'], $sch['next_date']]);
    t_eq('db: first invoice draft, 600,000 - 10% = 540,000', ['draft', 540000.0, 12, 2], [$inv['status'], (float)$inv['total'], (int)$inv['extend_months'], count($inv['items'])]);
    $row = tenant_find($pdo, $tid);
    t_eq('db: normal days back, converted, paid_until unchanged', [14, 14, true, $paidBefore, ''],
        [(int)$row['grace_days'], (int)$row['readonly_days'], !empty($row['trial_converted_at']), $row['paid_until'], trial_words($row, $today)]);
    t_eq('db: trial_converted event queued', 'trial_converted', (array_slice(billing_events_queue(), -1)[0][0] ?? ''));
    billing_events_clear();
    t_ok('db: second convert refused', !trial_convert($pdo, $tid, ['plan_id' => $planId], 0, $today)['ok']);
    t_ok('db: no ending / ended emails after converting', !array_filter(trials_due($pdo, $today), static fn($x) => $x['tenant_id'] === $tid));
    $b = billing_mail_build($pdo, 'trial_converted', $tid, [], false);
    t_ok('db: thank-you email names the plan and the first invoice', $b['ok'] && strpos($b['text'], 'Test licence – Monthly') !== false && strpos($b['text'], (string)$inv['number']) !== false);

    // Paying the first invoice moves paid_until by 12 months (from today: the trial already ended).
    t_eq('db: invoice marked sent', '', billing_set_status($pdo, $cv['document_id'], 'sent', 0));
    $pay = billing_record_payment($pdo, $cv['document_id'], 540000, 'TRIALS-TEST', 'cash', 0);
    billing_events_clear();
    t_ok('db: paid in full', $pay['ok'] && $pay['paid_in_full'], $pay['error']);
    t_eq('db: paid_until moved by 12 months', status_add_months(max($paidBefore, $today), 12), tenant_find($pdo, $tid)['paid_until']);
    t_ok('db: a business that has paid cannot start a trial', trial_start($pdo, $tid, 7, 0, $today) !== '');

    // Convert with the business's monthly fee (a second throw-away business).
    $pdo->prepare("INSERT INTO cp_tenants (slug, name, business_type, modules, domain, plan, monthly_fee, currency, paid_until, grace_days, readonly_days)
        VALUES (?, 'Trials Fee Lodge', 'full_hotel', ?, ?, 'Gold', '75000.00', 'MWK', ?, 14, 14)")->execute([$slug . 'f', json_encode(['pos']), $slug . 'f.example.invalid', $today]);
    $tid2 = (int)$pdo->lastInsertId();
    client_sync_from_tenant($pdo, $tid2, 0);
    trial_start($pdo, $tid2, 7, 0, $today);
    $cv2 = trial_convert($pdo, $tid2, ['plan_id' => 0, 'send_mode' => 'approve'], 0, $today);
    billing_events_clear();
    $inv2 = billing_doc($pdo, $cv2['document_id']);
    t_eq('db: fee conversion = the licence line, monthly, extends 1', [true, 'Gold licence', 75000.0, 1, 1], [$cv2['ok'], $inv2['items'][0]['item'] ?? '', (float)$inv2['total'],
        (int)$inv2['extend_months'], (int)recurring_find($pdo, $cv2['schedule_id'])['interval_months']]);
    t_eq('db: unpaid first invoice keeps the trial end', status_add_days($today, 7), tenant_find($pdo, $tid2)['paid_until']);

    // Subscribe a non-hosted client to a service plan, yearly, with an extra line.
    $sid = client_save($pdo, ['kind' => 'individual', 'name' => 'Trials Subscriber', 'email' => 'sub@example.invalid', 'currency' => 'MWK', 'source' => 'test'], 0);
    billing_events_clear();
    $svc = billing_catalog_save($pdo, 0, ['category' => 'service', 'sku' => '', 'item' => 'Test support – Monthly', 'description' => '', 'unit_price' => '20000',
        'unit' => 'month', 'cost_price' => '5000', 'currency' => 'MWK', 'is_active' => 1, 'sort' => 0, 'billing_interval' => 1], 0);
    $s1 = plan_subscribe($pdo, $sid, ['plan_id' => $svc, 'yearly' => true, 'next_date' => $today, 'send_mode' => 'approve',
        'extra' => [['item' => 'Backup add-on', 'qty' => '1', 'unit_price' => '1000'], ['item' => '', 'qty' => '1', 'unit_price' => '']]], 0);
    $r1 = recurring_find($pdo, $s1['schedule_id']);
    $items = recurring_items($r1);
    t_eq('db: subscription yearly, no licence extension, 3 lines', [true, 12, 0, 3], [$s1['ok'], (int)$r1['interval_months'], (int)$r1['extend_months'], count($items)]);
    t_eq('db: subscription total 240,000 - 24,000 + 1,000', 217000.0, billing_calc_totals($items, 0, 0)['total']);
    t_ok('db: a one-off item is not a plan', !plan_subscribe($pdo, $sid, ['plan_id' => (int)$pdo->query('SELECT id FROM cp_catalog_items WHERE billing_interval IS NULL LIMIT 1')->fetchColumn(), 'next_date' => $today], 0)['ok']);

    // Reports and the board.
    $fig = reports_trials(reports_trial_tenants($pdo), $today, $today, $today);
    t_ok('db: report counts the trials started and converted today', $fig['started'] >= 2 && $fig['converted'] >= 2);
    trials_ending_soon($pdo, $today, 7);
    t_eq('db: nothing was emailed', [], $sent);
} finally {
    $pdo->rollBack();
    billing_events_clear();
    billing_settings_cache(null, true);
    billing_mail_sender(null, true);
}
t_eq('db: everything rolled back', $before, $fingerprint());
t_done('trials_test');
