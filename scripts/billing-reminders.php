<?php
// CLI only. Kept for existing cron lines: runs ONLY the reminders step of the daily billing run (scripts/billing-daily.php,
// which replaces this script in the guide). Same lock, same dedupe keys and the same Automations setting (payment reminders:
// off / ask me first / send automatically), so it can never send a reminder the daily run already sent.
//   0 8 * * * cd /home/CPANELUSER/msp-control-panel && php scripts/billing-daily.php >> logs/billing-daily.log 2>&1
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

$r = billing_daily(db(), date('Y-m-d'), in_array('--dry-run', $argv, true), ['steps' => ['reminders']]);
echo date('Y-m-d H:i:s') . ' reminders: ' . $r['summary'] . "\n";
exit($r['ok'] ? 0 : 1);
