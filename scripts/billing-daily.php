<?php
// CLI only (cron). The daily billing run (BILLING.md Part 2): due recurring schedules become invoices (sent, queued in the
// Outbox or left as drafts per each schedule), then the reminders due today (Automations: payment reminders off / ask me
// first / send automatically), then the free-trial emails (BILLING.md Part 3: trial ending / trial ended, per Automations),
// then a one-line summary. One run at a time (storage/billing-daily.lock); every email has a
// dedupe key, so running it twice the same day never sends anything twice. While MAIL_TEST_TO is set in .env every email
// goes only to that address.
// cPanel > Cron Jobs, once a day, for example 08:00:
//   0 8 * * * cd /home/CPANELUSER/msp-control-panel && php scripts/billing-daily.php >> logs/billing-daily.log 2>&1
// Options: --dry-run (show what would happen, change nothing), --date=YYYY-MM-DD (with --dry-run only: preview another day).
// Exit code 0 when everything worked, 1 when anything failed or another run held the lock.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/billing_mail.php';
if (is_file(__DIR__ . '/../app/clients.php')) {
    require_once __DIR__ . '/../app/clients.php';
}
if (is_file(__DIR__ . '/../app/recurring.php')) {
    require_once __DIR__ . '/../app/recurring.php';
}

$dry = in_array('--dry-run', $argv, true);
$today = date('Y-m-d');
foreach ($dry ? $argv : [] as $a) {
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $a, $m) && checkdate((int)substr($m[1], 5, 2), (int)substr($m[1], 8, 2), (int)substr($m[1], 0, 4))) {
        $today = $m[1];
    }
}

$r = billing_daily(db(), $today, $dry);
echo date('Y-m-d H:i:s') . ' ' . $r['summary'] . "\n";
exit($r['ok'] ? 0 : 1);
