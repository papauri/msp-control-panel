<?php
// Pure tests for the billing automations: mode resolution, dedupe keys, event plans, Outbox decision rules and the daily-run
// step ordering (every dependency is a fake). No database, no network, nothing can be sent.
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/automation.php';

// A PDO the code can be handed but that must never be used (no driver, no connection).
class AutomationTestPdo extends PDO
{
    public function __construct()
    {
    }
}
$pdo = new AutomationTestPdo();

// ---------------------------------------------------------------- modes
$def = automation_defaults();
t_eq('default thank payment', 'auto', $def['auto_thank_payment']);
t_eq('default thank business', 'auto', $def['auto_thank_business']);
t_eq('default welcome', 'approve', $def['auto_welcome']);
t_eq('default quote accepted', 'auto', $def['auto_quote_accepted']);
t_eq('default reminders', 'approve', $def['auto_reminders']);
t_eq('nothing stored -> default', 'approve', automation_mode_resolve('auto_reminders', null));
t_eq('nothing stored -> default auto', 'auto', automation_mode_resolve('auto_thank_payment', null));
t_eq('stored auto', 'auto', automation_mode_resolve('auto_welcome', 'auto'));
t_eq('stored off', 'off', automation_mode_resolve('auto_thank_payment', 'off'));
t_eq('stored upper case', 'approve', automation_mode_resolve('auto_thank_payment', ' APPROVE '));
t_eq('legacy reminders 1 -> approve', 'approve', automation_mode_resolve('auto_reminders', '1'));
t_eq('legacy reminders 0 -> off', 'off', automation_mode_resolve('auto_reminders', '0'));
t_eq('garbage -> default', 'auto', automation_mode_resolve('auto_quote_accepted', 'yes please'));
t_eq('unknown key -> off', 'off', automation_mode_resolve('auto_nothing', null));
t_eq('catalog covers every setting', array_keys($def), array_keys(automation_catalog()));

// ---------------------------------------------------------------- dedupe keys
t_eq('key payment', 'thank_you_payment:12', automation_dedupe_key('thank_you_payment', 12));
t_eq('key business', 'thank_you_business:7', automation_dedupe_key('thank_you_business', 7));
t_eq('key welcome', 'welcome:3', automation_dedupe_key('welcome', 3));
t_eq('key quote', 'quote_accepted:9', automation_dedupe_key('quote_accepted', 9));
t_eq('key recurring', 'recurring_invoice:44', automation_dedupe_key('recurring_invoice', 44));
t_eq('key reminder per day', 'reminder:7:2026-11-09', automation_dedupe_key('reminder', 7, '2026-11-09'));
t_ok('reminder other day differs', automation_dedupe_key('reminder', 7, '2026-11-10') !== automation_dedupe_key('reminder', 7, '2026-11-09'));
t_eq('reminder datetime trimmed to the day', 'reminder:7:2026-11-09', automation_dedupe_key('reminder', 7, '2026-11-09 08:00:00'));
t_ok('same input same key', automation_dedupe_key('thank_you_payment', 5) === automation_dedupe_key('thank_you_payment', 5));
t_eq('kind sanitised', 'welcome:1', automation_dedupe_key("wel'come", 1));

// ---------------------------------------------------------------- event plans
$modes = ['auto_thank_payment' => 'auto', 'auto_thank_business' => 'approve', 'auto_welcome' => 'off', 'auto_quote_accepted' => 'auto',
    'auto_reminders' => 'approve'];
$p = automation_plan_event('payment_recorded', ['payment_id' => 12, 'document_id' => 7, 'paid_in_full' => true], $modes);
t_eq('paid in full: two emails', ['thank_you_payment', 'thank_you_business'], array_column($p, 'kind'));
t_eq('paid in full: refs', [12, 7], array_column($p, 'ref_id'));
t_eq('paid in full: modes', ['auto', 'approve'], array_column($p, 'mode'));
t_eq('paid in full: keys', ['thank_you_payment:12', 'thank_you_business:7'], array_column($p, 'dedupe'));
$p = automation_plan_event('payment_recorded', ['payment_id' => 13, 'document_id' => 7, 'paid_in_full' => false], $modes);
t_eq('part payment: thank you only', ['thank_you_payment'], array_column($p, 'kind'));
$p = automation_plan_event('payment_recorded', ['payment_id' => 14, 'document_id' => null, 'paid_in_full' => true], $modes);
t_eq('payment without invoice: thank you only', ['thank_you_payment'], array_column($p, 'kind'));
$p = automation_plan_event('quote_accepted', ['document_id' => 9], $modes);
t_eq('quote accepted plan', [['quote_accepted', 9, 'auto']], array_map(function ($x) {
    return [$x['kind'], $x['ref_id'], $x['mode']];
}, $p));
$p = automation_plan_event('client_created', ['client_id' => 3], $modes);
t_eq('client created plan (mode off is reported)', [['welcome', 3, 'off']], array_map(function ($x) {
    return [$x['kind'], $x['ref_id'], $x['mode']];
}, $p));
t_eq('unknown event: nothing', [], automation_plan_event('something_else', ['payment_id' => 1], $modes));
t_eq('missing id: nothing', [], automation_plan_event('payment_recorded', [], $modes));

// Off mode never touches the database (the fake PDO would throw).
t_eq('dispatch off', 'off', automation_dispatch($pdo, 'welcome', 3, [], 'x', 'welcome:3', 'off')['result']);
t_eq('dispatch draft = off', 'off', automation_dispatch($pdo, 'welcome', 3, [], 'x', 'welcome:3', 'draft')['result']);

// ---------------------------------------------------------------- outbox decision rules
$rules = [
    ['pending', 'send', true], ['pending', 'skip', true],
    ['failed', 'send', true], ['failed', 'skip', true],
    ['skipped', 'send', true], ['skipped', 'skip', false],
    ['sent', 'send', false], ['sent', 'skip', false],
    ['pending', 'delete', false], ['weird', 'send', false],
];
foreach ($rules as [$status, $action, $allowed]) {
    t_eq("decide $action on $status", $allowed, outbox_decision_error($status, $action) === '');
}
t_eq('already sent wording', 'This email has already been sent.', outbox_decision_error('sent', 'send'));
t_eq('claimable for send', ['pending', 'failed', 'skipped'], outbox_claimable('send'));
t_eq('claimable for skip', ['pending', 'failed'], outbox_claimable('skip'));
foreach (outbox_claimable('send') as $s) {
    t_ok("claimable send agrees with rules ($s)", outbox_decision_error($s, 'send') === '');
}
foreach (outbox_claimable('skip') as $s) {
    t_ok("claimable skip agrees with rules ($s)", outbox_decision_error($s, 'skip') === '');
}

// Preview lines: skip the title line and empty lines.
t_eq('preview lines', ['Dear Grace,', 'Para one.'], outbox_text_lines("RECEIPT - ProManaged IT\n\nDear Grace,\n\nPara one.\n\nPara two.", 2));
t_eq('preview lines empty', [], outbox_text_lines('', 3));

// ---------------------------------------------------------------- the daily run (fakes)
function daily_fakes(array &$log, array $over = []): array
{
    return $over + [
        'lock' => function () use (&$log) {
            $log[] = 'lock';
            return 'LOCK';
        },
        'unlock' => function ($h) use (&$log) {
            $log[] = 'unlock:' . $h;
        },
        'modes' => function () {
            return ['auto_reminders' => 'approve'];
        },
        'recurring_due' => function ($pdo, $today) use (&$log) {
            $log[] = 'recurring_due:' . $today;
            return [
                ['id' => 1, 'title' => 'Hosting', 'send_mode' => 'auto'],
                ['id' => 2, 'title' => 'Support', 'send_mode' => 'approve'],
                ['id' => 3, 'title' => 'Domain', 'send_mode' => 'draft'],
            ];
        },
        'recurring_run' => function ($pdo, $id, $today) use (&$log) {
            $log[] = 'run:' . $id;
            return ['ok' => true, 'document_id' => 100 + $id, 'send_mode' => [1 => 'auto', 2 => 'approve', 3 => 'draft'][$id], 'error' => ''];
        },
        'due_reminders' => function ($pdo, $today) use (&$log) {
            $log[] = 'due_reminders:' . $today;
            return [['id' => 7, 'number' => '170134', 'days' => -3], ['id' => 8, 'number' => '170135', 'days' => 7]];
        },
        'dispatch' => function ($pdo, $kind, $ref, $opts, $reason, $dedupe, $mode) use (&$log) {
            $log[] = 'dispatch:' . $kind . ':' . $ref . ':' . $mode . ':' . $dedupe;
            if ($kind === 'reminder' && $ref === 8) {
                return ['result' => 'duplicate', 'outbox_id' => 0, 'error' => ''];
            }
            return ['result' => $mode === 'auto' ? 'sent' : 'pending', 'outbox_id' => 500 + $ref, 'error' => ''];
        },
        'exists' => function ($pdo, $key) use (&$log) {
            $log[] = 'exists:' . $key;
            return $key === 'reminder:8:2026-11-09';
        },
        'tidy' => function ($pdo) use (&$log) {
            $log[] = 'tidy';
            return 0;
        },
        'supersede' => function ($pdo, $docId, $keep) use (&$log) {
            $log[] = 'supersede:' . $docId . ':' . $keep;
            return 0;
        },
    ];
}

$log = [];
$r = billing_daily($pdo, '2026-11-09', false, daily_fakes($log));
t_eq('daily: steps in order', ['lock', 'recurring', 'reminders', 'trials', 'summary'], $r['steps']);
t_eq('daily: call order', [
    'lock', 'recurring_due:2026-11-09',
    'run:1', 'dispatch:recurring_invoice:101:auto:recurring_invoice:101',
    'run:2', 'dispatch:recurring_invoice:102:approve:recurring_invoice:102',
    'run:3',
    'tidy', 'due_reminders:2026-11-09',
    'dispatch:reminder:7:approve:reminder:7:2026-11-09', 'supersede:7:507',
    'dispatch:reminder:8:approve:reminder:8:2026-11-09',
    'unlock:LOCK',
], $log);
t_ok('daily: ok', $r['ok'] === true && !$r['failures']);
t_eq('daily: counts', ['invoices' => 3, 'sent' => 1, 'queued' => 2, 'drafts' => 1, 'failed' => 0, 'duplicates' => 1], $r['counts']);
t_eq('daily: recurring results', ['sent', 'pending', 'draft'], array_column($r['recurring'], 'result'));
t_eq('daily: reminder results', ['pending', 'duplicate'], array_column($r['reminders'], 'result'));
t_ok('daily: reminder label words', strpos($r['reminders'][0]['label'], 'due in 3 days') !== false && strpos($r['reminders'][1]['label'], '7 days overdue') !== false);
t_eq('daily: summary', 'billing-daily: 3 invoice(s) created (1 draft), 1 email(s) sent, 2 waiting in the Outbox, 1 already handled, 0 failed', $r['summary']);

// Dry run: no lock, nothing created, dispatched, tidied or superseded.
$log = [];
$r = billing_daily($pdo, '2026-11-09', true, daily_fakes($log));
t_eq('dry: steps', ['recurring', 'reminders', 'trials', 'summary'], $r['steps']);
t_eq('dry: only reads', ['recurring_due:2026-11-09', 'due_reminders:2026-11-09', 'exists:reminder:7:2026-11-09', 'exists:reminder:8:2026-11-09'], $log);
t_eq('dry: recurring would create', ['would_create', 'would_create', 'would_create'], array_column($r['recurring'], 'result'));
t_eq('dry: reminders would queue / already handled', ['would_queue', 'duplicate'], array_column($r['reminders'], 'result'));
t_eq('dry: summary', 'billing-daily (dry run): 3 invoice(s) would be created, 0 reminder(s) would be sent, 1 would wait in the Outbox, 1 already handled', $r['summary']);

// Another run holds the lock: nothing at all happens, exit as a failure.
$log = [];
$r = billing_daily($pdo, '2026-11-09', false, daily_fakes($log, ['lock' => function () use (&$log) {
    $log[] = 'lock';
    return null;
}]));
t_eq('locked: only tried the lock', ['lock'], $log);
t_ok('locked: not ok', $r['ok'] === false && $r['locked'] === true);
t_eq('locked: summary', 'billing-daily: another run is in progress, nothing done', $r['summary']);

// Reminders off: no reminder work; auto mode sends; a failed send fails the run (and is counted) but the rest still runs.
$log = [];
$r = billing_daily($pdo, '2026-11-09', false, daily_fakes($log, ['modes' => function () {
    return ['auto_reminders' => 'off'];
}]));
t_ok('reminders off: not read', !in_array('due_reminders:2026-11-09', $log, true) && !in_array('tidy', $log, true));
t_ok('reminders off: noted', in_array('Automatic reminders are off.', $r['notes'], true));
$log = [];
$r = billing_daily($pdo, '2026-11-09', false, daily_fakes($log, [
    'modes' => function () {
        return ['auto_reminders' => 'auto'];
    },
    'recurring_run' => function ($pdo, $id, $today) use (&$log) {
        $log[] = 'run:' . $id;
        return $id === 2 ? ['ok' => false, 'error' => 'client has no items'] : ['ok' => true, 'document_id' => 100 + $id, 'send_mode' => 'draft'];
    },
    'dispatch' => function ($pdo, $kind, $ref, $opts, $reason, $dedupe, $mode) use (&$log) {
        $log[] = 'dispatch:' . $kind . ':' . $ref . ':' . $mode;
        return $ref === 7 ? ['result' => 'failed', 'outbox_id' => 9, 'error' => 'No email address'] : ['result' => 'sent', 'outbox_id' => 10, 'error' => ''];
    },
]));
t_ok('failure: run continues to the reminders', in_array('dispatch:reminder:7:auto', $log, true) && in_array('dispatch:reminder:8:auto', $log, true));
t_ok('failure: auto mode never supersedes', !preg_grep('/^supersede/', $log));
t_ok('failure: not ok', $r['ok'] === false);
t_eq('failure: counts', ['invoices' => 2, 'sent' => 1, 'queued' => 0, 'drafts' => 2, 'failed' => 2, 'duplicates' => 0], $r['counts']);
t_ok('failure: summary names them', strpos($r['summary'], 'Support: client has no items') !== false && strpos($r['summary'], 'Reminder 170134: No email address') !== false);
t_ok('failure: lock released', end($log) === 'unlock:LOCK');

// A throwing dependency is contained: the lock is still released and the run reports a failure.
$log = [];
$r = billing_daily($pdo, '2026-11-09', false, daily_fakes($log, ['due_reminders' => function () {
    throw new RuntimeException('db gone');
}]));
t_ok('throw: reported', $r['ok'] === false && strpos($r['summary'], 'db gone') !== false);
t_ok('throw: unlocked', end($log) === 'unlock:LOCK');

// Recurring not installed yet: a note, no failure.
$log = [];
$r = billing_daily($pdo, '2026-11-09', false, daily_fakes($log, ['recurring_due' => function () {
    return null;
}, 'steps' => ['recurring']]));
t_ok('no recurring module: noted, ok', $r['ok'] && in_array('Recurring invoices are not available yet.', $r['notes'], true));
t_eq('reminders-only wrapper steps', ['lock', 'reminders', 'summary'],
    billing_daily($pdo, '2026-11-09', false, daily_fakes($log, ['steps' => ['reminders']]))['steps']);

// The real lock: a second holder is refused while the first holds it.
$lockFile = sys_get_temp_dir() . '/automation_test_' . getmypid() . '.lock';
$h1 = billing_daily_lock($lockFile);
t_ok('lock: first holder gets it', $h1 !== null);
// flock is per open file description on Windows and Linux alike: a second fopen + LOCK_NB must fail while held.
$h2 = billing_daily_lock($lockFile);
t_ok('lock: second holder refused', $h2 === null);
billing_daily_unlock($h1);
$h3 = billing_daily_lock($lockFile);
t_ok('lock: free again after unlock', $h3 !== null);
billing_daily_unlock($h3);
@unlink($lockFile);

t_done('automation');
