<?php
// Backups (owner 2026-11-09). Each business backs itself up on its own server; this page asks them to (one button,
// or one business) and shows what each reports. The panel's own records are backed up here, encrypted, and can be
// downloaded as plain SQL (for moving the panel to its own database, or an offline copy) after password + code.
// Runs automatically on the first sign-in of each day and from the cron job (scripts/backup-all.php).
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);
$me = (int)$admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $s = static fn(string $k): string => isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : '';
    $action = $s('action');
    if ($action === 'run_all') {
        if (!backup_can_detach()) {
            // This server cannot send the page first. The panel's own backup is quick and is what was asked for, so it
            // runs now; the slow part (nudging each business, up to 4 s each) is skipped: they see the request at
            // their next hourly check-in.
            try {
                $run = backup_run_all($pdo, 'button', $me);
                flash_set($run['panel']['ok'] ? 'info' : 'error', $run['panel']['ok']
                    ? 'Backup done: the panel\'s own records are saved, and every business has been asked to back itself up at its next check-in (within the hour).'
                    : 'The panel\'s own backup failed: ' . $run['panel']['error'] . ' Every business has still been asked to back itself up.');
            } catch (Throwable $e) {
                error_log('backup button: ' . $e->getMessage());
                flash_set('error', 'The backup could not be run. Nothing was changed; try again or run php scripts/backup-all.php.');
            }
            header('Location: backups.php');
            exit;
        }
        flash_set('info', 'Backup started: the panel\'s own records now, and every business has been asked to back itself up. Businesses report back within the hour; refresh this page to see.');
        header('Location: backups.php');
        if (!backup_finish_response()) {
            error_log('backup button: the response could not be ended early, so the backup was skipped (the cron job runs it).');
            exit;
        }
        try {
            $run = backup_run_all($pdo, 'button', $me);
            backup_nudge_all($pdo, $run['asked']);
        } catch (Throwable $e) {
            error_log('backup button: ' . $e->getMessage());
        }
        exit;
    }
    if ($action === 'run_one') {
        $tid = ctype_digit($s('tenant_id')) ? (int)$s('tenant_id') : 0;
        $asked = $tid > 0 ? backup_request($pdo, $tid) : [];
        if (!$asked) {
            flash_set('error', 'That business no longer exists.');
            header('Location: backups.php');
            exit;
        }
        audit_log($pdo, $me, 'backup_asked', $tid, null, null);
        flash_set('info', 'Asked. The business backs up at its next check-in (straight away when it is online, otherwise within the hour).');
        header('Location: backups.php');
        if (backup_finish_response()) { // otherwise the business still sees the request at its next hourly check-in
            backup_nudge_all($pdo, $asked);
        }
        exit;
    }
    if ($action === 'download') {
        // The panel's records as plain SQL: for moving it to its own database, or an offline copy. Password + fresh code.
        $which = $s('which');
        if (!auth_reconfirm($pdo, $me, $s('my_password'), (string)preg_replace('/\D/', '', $s('my_code')))) {
            flash_set('error', 'Your password or code was not accepted. Nothing was downloaded. Use a new code (each code works once).');
            header('Location: backups.php');
            exit;
        }
        if ($which !== 'now') {
            $sql = backup_panel_open($which);
            if ($sql === null) {
                flash_set('error', 'That backup could not be opened (missing, changed, or the signing key is not the one it was made with).');
                header('Location: backups.php');
                exit;
            }
        }
        audit_log($pdo, $me, 'panel_database_downloaded', null, null, ['which' => $which === 'now' ? 'current' : $which]);
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="licence-panel-database-' . ($which === 'now' ? date('Ymd-His') : substr($which, 6, 15)) . '.sql"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        if ($which === 'now') {
            @set_time_limit(300);
            dbexport_write($pdo, static function (string $chunk): void {
                echo $chunk;
            });
        } else {
            echo $sql;
        }
        exit;
    }
    http_response_code(400);
    echo 'Invalid request.';
    exit;
}

$state = backup_state();
$now = time();
$tenants = $pdo->query('SELECT id, name, slug FROM cp_tenants ORDER BY name')->fetchAll();
$panelCopies = backup_panel_list();
$lastRun = is_array($state['last_run'] ?? null) ? $state['last_run'] : null;
$whyWords = ['button' => 'the Back up now button', 'first_sign_in' => 'the first sign-in of the day', 'cron' => 'the daily cron job'];
$when = static fn(int $t): string => $t > 0 ? e(date('Y-m-d H:i', $t)) : view_empty();
$mb = static fn(int $b): string => $b > 0 ? e(number_format($b / 1048576, 1) . ' MB') : view_empty();
$pillFor = ['ok' => 'active', 'asked' => 'grace', 'none' => 'grace', 'overdue' => 'read_only', 'failed' => 'suspended'];

view_head('Backups');
echo view_page_head('Backups');
view_flash();
?>
<div class="card">
    <p>Every business backs <strong>itself</strong> up on its own server, so your clients' data never leaves them. This panel asks for those backups and shows what each business reports. The panel's own records are backed up here, encrypted.</p>
    <p class="muted">Runs by itself on the first sign-in of each day<?= $lastRun ? ' (last run ' . $when((int)$lastRun['at']) . ', started by ' . e($whyWords[(string)$lastRun['why']] ?? (string)$lastRun['why']) . ')' : '' ?>, and every night from the cron job if it is set up (Guide, section 13).</p>
    <form method="post" action="backups.php" class="spaced">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="run_all">
        <button type="submit">Back up everything now</button>
    </form>
</div>

<h2>Businesses</h2>
<?php if (!$tenants): ?>
    <p>No businesses yet.</p>
<?php else: ?>
<div class="scroll"><table class="list"><thead><tr>
    <?= view_th('Business', 'The customer.', 'Customer') ?>
    <?= view_th('Backup', 'What the business last reported about its own backups.', 'Last report') ?>
    <?= view_th('Last good backup', 'When its newest backup finished, on its own server.', 'Finished at') ?>
    <?= view_th('Size', 'Size of that backup file (compressed).', 'Compressed') ?>
    <?= view_th('Copy kept off-site', 'When someone at the business last downloaded a backup to keep off the server (Backup management page in its system). A backup only survives a server failure once a copy is kept elsewhere.', 'Last download') ?>
    <?= view_th('Asked', 'When this panel last asked it to back up.', 'Last asked') ?>
    <?= view_th('', 'Ask this business alone to back up now.', 'Ask now') ?>
</tr></thead><tbody>
<?php foreach ($tenants as $t):
    $v = backup_tenant_view((array)($state['tenants'][(string)$t['id']] ?? []), $now); ?>
    <tr>
        <td data-label="Business"><a href="tenant.php?id=<?= (int)$t['id'] ?>"><?= e($t['name']) ?></a></td>
        <td data-label="Backup"><span class="pill pill-<?= e($pillFor[$v['state']] ?? 'grace') ?>"><?= e($v['words']) ?></span></td>
        <td data-label="Last good backup" class="nw"><?= $when($v['last_at']) ?></td>
        <td data-label="Size" class="nw"><?= $mb($v['size']) ?></td>
        <td data-label="Copy kept off-site" class="nw"><?= $v['offsite_at'] ? $when($v['offsite_at']) : 'Never' ?></td>
        <td data-label="Asked" class="nw"><?= $when($v['requested_at']) ?></td>
        <td data-label="Ask now"><form method="post" action="backups.php" class="inline"><?= csrf_field() ?>
            <input type="hidden" name="action" value="run_one"><input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>">
            <button type="submit" class="secondary">Back up now</button></form></td>
    </tr>
<?php endforeach; ?>
</tbody></table></div>
<p class="muted">A business that has not checked in since this feature was added shows "Nothing reported yet". Its own system needs the update that reports backups.</p>
<?php endif; ?>

<h2>This panel's own records</h2>
<div class="card">
    <?php if ($lastRun && empty($lastRun['panel_ok'])): ?>
        <p class="flash flash-error">The last backup of the panel's records failed: <?= e((string)$lastRun['panel_error']) ?></p>
    <?php endif; ?>
    <p>Encrypted copies kept on this server (outside the website folder), newest first. The newest <?= (int)BACKUP_KEEP ?> are kept. They can only be opened with this panel's signing key.</p>
    <?php if (!$panelCopies): ?>
        <p class="muted">None yet. Press <strong>Back up everything now</strong>.</p>
    <?php else: ?>
        <ul>
        <?php foreach (array_slice($panelCopies, 0, 5) as $c): ?>
            <li><?= $when($c['at']) ?> <span class="muted">(<?= e(number_format($c['size'] / 1024, 0)) ?> KB)</span></li>
        <?php endforeach; ?>
        <?php if (count($panelCopies) > 5): ?><li class="muted">and <?= count($panelCopies) - 5 ?> older</li><?php endif; ?>
        </ul>
    <?php endif; ?>
    <h2>Download the panel's database</h2>
    <p class="muted">A plain SQL file of every panel table (cp_...). Use it to move the panel to its own database (Guide, section 14) or to keep an offline copy. It holds password hashes and licence-key hashes: keep it private, never email it. Needs your password and a new 6-digit code.</p>
    <form method="post" action="backups.php" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="download">
        <label>Which copy
            <select name="which">
                <option value="now">As it is right now</option>
                <?php foreach ($panelCopies as $c): ?>
                    <option value="<?= e($c['name']) ?>">Backup of <?= e(date('Y-m-d H:i', $c['at'])) ?></option>
                <?php endforeach; ?>
            </select></label>
        <label>Your password <input type="password" name="my_password" required maxlength="1024" autocomplete="current-password"></label>
        <label>6-digit code <input type="text" name="my_code" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code"></label>
        <button type="submit">Download</button>
    </form>
</div>
<?php
view_foot();
