<?php
// CLI only (cron). The daily backup run: backs up the panel's own records (encrypted, storage/backups) and asks every
// business to back itself up at its next check-in, then nudges each one to check in now.
// cPanel > Cron Jobs, once a day, for example 02:30:
//   30 2 * * * cd /home/CPANELUSER/msp-control-panel && php scripts/backup-all.php >> logs/backup-cron.log 2>&1
// Same as the Back up everything now button. Exit code 0 when the panel backup worked, 1 when it did not.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$run = backup_run_all($pdo, 'cron', null);
backup_nudge_all($pdo, $run['asked']);
echo date('Y-m-d H:i:s') . ' panel backup: ' . ($run['panel']['ok'] ? 'ok ' . $run['panel']['name'] . ' (' . $run['panel']['size'] . ' bytes)' : 'FAILED - ' . $run['panel']['error'])
    . '; businesses asked: ' . count($run['asked']) . "\n";
exit($run['panel']['ok'] ? 0 : 1);
