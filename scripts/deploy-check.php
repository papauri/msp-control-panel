<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); } // never over the web; exit 1 so a non-CLI php in cron never lets `&&` run the update
// The panel's own "Update from Remote" (Deployments page, app/deploy.php). No cPanel credentials: cron runs the pull with
// cPanel's own command-line UAPI as the account itself. cPanel > Cron Jobs, every 5 minutes (one line; Guide section 18):
//
//   */5 * * * * cd /home/USER/msp-control-panel && php scripts/deploy-check.php --want && /usr/local/cpanel/bin/uapi --output=jsonpretty VersionControl update repository_root=/home/USER/msp-control-panel branch=main > storage/deploy-last.json 2>&1; php scripts/deploy-check.php --report storage/deploy-last.json
//
//   --want           exit 0 when this panel's own live copy has an open request (and marks it picked up), else exit 1,
//                    so the uapi pull runs only when asked. The uapi command pulls only because `branch` is given.
//   --report FILE    always runs (after the `;`): records the commit checked out now (read from .git, no git command) and,
//                    when a request was picked up, the result in FILE (written by the uapi command after the pick-up;
//                    an older or missing file is ignored). The file is removed once recorded.
// Exit codes: --want 0 = run the update, 1 = nothing to do (or a fault); --report 0 = fine, 1 = a fault.
require_once __DIR__ . '/../app/bootstrap.php';

const DEPLOY_CHECK_NO_RESULT_SECONDS = 900; // picked up, but no result file 15 minutes later: recorded as failed

$mode = $argv[1] ?? '';
try {
    $pdo = db();
    $now = time();

    if ($mode === '--want') {
        $pdo->beginTransaction();
        $t = deploy_target_for($pdo, null, true);
        $p = $t ? deploy_pending($t, $now) : null;
        if ($p === null || (int)$t['picked_up_id'] >= $p['id']) {
            $pdo->commit();
            exit(1);
        }
        $pdo->prepare('UPDATE cp_deploy_targets SET picked_up_id = ?, picked_up_at = ? WHERE id = ?')
            ->execute([$p['id'], date('Y-m-d H:i:s', $now), (int)$t['id']]);
        $pdo->commit();
        echo date('Y-m-d H:i:s') . ' deploy: request ' . $p['id'] . ' picked up, running Update from Remote on ' . $t['branch'] . "\n";
        exit(0);
    }

    if ($mode === '--report') {
        $file = (string)($argv[2] ?? '');
        $t = deploy_target_for($pdo, null);
        if ($t === null) {
            exit(0); // no live copy set up for this panel on the Deployments page
        }
        $report = ['seen_id' => 0, 'done_id' => 0, 'ok' => null, 'msg' => '', 'sha' => (string)deploy_git_head(APP_ROOT . '/.git')];
        $picked = (int)$t['picked_up_id'];
        $pickedAt = deploy_ts($t['picked_up_at']);
        $consume = false;
        if ($picked > (int)$t['done_id']) {
            clearstatcache();
            if ($file !== '' && is_file($file) && @filemtime($file) >= $pickedAt) {
                $res = deploy_uapi_result((string)@file_get_contents($file, false, null, 0, 1048576), (string)$t['branch']);
                $report = ['seen_id' => $picked, 'done_id' => $picked, 'ok' => $res['ok'], 'msg' => $res['msg']] + $report;
                $consume = true;
            } elseif ($now - $pickedAt > DEPLOY_CHECK_NO_RESULT_SECONDS) {
                $report = ['seen_id' => $picked, 'done_id' => $picked, 'ok' => false,
                    'msg' => 'Picked up, but the update command left no result. Check the cron line (Guide, section 18).'] + $report;
            }
        }
        $ch = deploy_apply_report($pdo, (int)$t['id'], $report, $now);
        if ($consume) {
            @unlink($file);
        }
        if (isset($ch['done_id'])) {
            echo date('Y-m-d H:i:s') . ' deploy: request ' . $ch['done_id'] . ' ' . ($ch['result_ok'] ? 'done' : 'FAILED') . ': ' . $ch['result_msg'] . "\n";
        }
        exit(0);
    }

    fwrite(STDERR, "Usage: php scripts/deploy-check.php --want | --report FILE\n");
    exit(1);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'deploy-check: ' . get_class($e) . ': ' . deploy_scrub($e->getMessage()) . "\n");
    exit(1);
}
