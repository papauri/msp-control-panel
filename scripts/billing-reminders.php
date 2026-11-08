<?php
// CLI only (cron). Sends the invoice reminders due today (Settings: reminder_days, e.g. 3 days before, on the due date, 7 and 14
// days after), one email per invoice, when Settings > auto_reminders is on. Each send is logged in cp_email_log and audited.
// cPanel > Cron Jobs, once a day, for example 08:00:
//   0 8 * * * cd /home/CPANELUSER/msp-control-panel && php scripts/billing-reminders.php >> logs/billing-reminders.log 2>&1
// While MAIL_TEST_TO is set in .env every reminder goes only to that address. Exit code 0 when every send worked, 1 otherwise.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/billing.php';
require_once __DIR__ . '/../app/billing_mail.php';

$stamp = date('Y-m-d H:i:s');
if (billing_setting('auto_reminders') !== '1') {
    echo $stamp . " reminders: automatic reminders are off (Settings), nothing sent\n";
    exit(0);
}

$pdo = db();
$today = date('Y-m-d');
$due = billing_due_reminders($pdo, $today);
$sent = 0;
$failed = [];
foreach ($due as $row) {
    $id = is_array($row) ? (int)($row['id'] ?? 0) : (int)$row;
    if ($id <= 0) {
        continue;
    }
    $num = is_array($row) && isset($row['number']) ? (string)$row['number'] : '#' . $id;
    $r = billing_send($pdo, 'reminder', $id, ['today' => $today], 0);
    if ($r['ok']) {
        $sent++;
    } else {
        $failed[] = $num . ' (' . $r['error'] . ')';
    }
}
echo $stamp . ' reminders: ' . count($due) . ' due, ' . $sent . ' sent, ' . count($failed) . ' failed'
    . ($failed ? ': ' . implode('; ', $failed) : '') . "\n";
exit($failed ? 1 : 0);
