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
t_eq('yearly with no discount: no discount line', 1, count(plan_lines($monthly, true, 0)['lines']));
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
    t_ok('db: audited', (int)$pdo->query("SELECT COUNT(*) FROM cp_audit_log WHERE tenant_id = " . $tid . " AND action IN ('trial_started','trial_extended')")->fetchColumn() === 2);

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
