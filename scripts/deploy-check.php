<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); } // never over the web; exit 1 so a non-CLI php in cron never lets `&&` run the update
// The panel's own "Update from Remote" (Deployments page, app/deploy.php). No cPanel credentials: cron runs the pull with
// git itself as the account. cPanel > Cron Jobs, every 5 minutes (one line; Guide section 18; main = the branch checked out):
//
//   */5 * * * * cd /home/USER/msp-control-panel && /usr/local/bin/php scripts/deploy-check.php --want && { GIT_TERMINAL_PROMPT=0 /usr/bin/git pull --ff-only origin main; echo "exit=$?"; } > storage/deploy-last.json 2>&1; /usr/local/bin/php scripts/deploy-check.php --report storage/deploy-last.json
//
// Why git and not cPanel's uapi: cron runs inside CageFS on this host, where uapi fails ("Failed to execute
// /usr/local/cpanel/cpanel"). The panel runs straight from the repository folder (no .cpanel.yml), so a fast-forward-only
// pull of the branch already checked out IS cPanel's Update from Remote. GIT_TERMINAL_PROMPT=0: git fails instead of
// waiting for a password. Local changes on the server stop the pull (reported as failed).
// Why /usr/local/bin/php: cron's plain `php` may be php-cgi (not the command line); this script then refuses (exit 1), so
// nothing is pulled and nothing recorded. Use the command-line PHP path your host gives.
//
//   --want           exit 0 when this panel's own live copy has an open request (and marks it picked up), else exit 1,
//                    so the git pull runs only when asked (always name the branch already checked out).
//   --report FILE    always runs (after the `;`): records the commit checked out now (read from .git, no git command) and,
//                    when a request was picked up, the result in FILE (git's output and "exit=N", written after the
//                    pick-up; an older or missing file is ignored). The file is removed once recorded.
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
            $late = $now - $pickedAt > DEPLOY_CHECK_NO_RESULT_SECONDS;
            $body = ($file !== '' && is_file($file) && @filemtime($file) >= $pickedAt) ? (string)@file_get_contents($file, false, null, 0, 1048576) : null;
            if ($body !== null && !$late && !deploy_result_complete($body)) {
                // A pull still running (no `exit=N` / closing brace yet) when the next cron run comes: wait for it
                // (owner 2026-11-24: the cron may run every minute). After the no-result time whatever is there is read.
            } elseif ($body !== null) {
                $res = deploy_uapi_result($body, (string)$t['branch']);
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

    fwrite(STDERR, "Usage: /usr/local/bin/php scripts/deploy-check.php --want | --report FILE (cron line: top of this file, Guide section 18)\n");
    exit(1);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'deploy-check: ' . get_class($e) . ': ' . deploy_scrub($e->getMessage()) . "\n");
    exit(1);
}
