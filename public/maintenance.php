<?php
// Maintenance windows (owner 2026-11-23, app/maintenance.php). Schedule a window for one, several or all businesses: their
// staff see a notice from the chosen time, the chosen part goes offline for the window and comes back by itself at the end.
// End now / Cancel bring it back early. The generic message is edited (or reverted) here. Times are the panel's time zone.
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);
$me = (int)$admin['id'];
$tz = date_default_timezone_get();
$ready = maint_table_ready($pdo);

/** Sends the redirect first, then nudges each business so it checks in now (as tenant.php and backups.php do). */
function maint_back_and_nudge(PDO $pdo, string $to, array $tenantIds): void
{
    header('Location: ' . $to);
    $tenantIds = array_values(array_unique(array_map('intval', $tenantIds)));
    if ($tenantIds) {
        backup_finish_response(); // when this server cannot let the browser go first, it waits a few seconds for the nudges
        backup_nudge_all($pdo, $tenantIds);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $s = static fn(string $k): string => isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : '';
    $action = $s('action');
    $back = 'maintenance.php';
    if (!$ready) {
        flash_set('error', 'Maintenance is not set up on this panel yet: apply migration 014 (php scripts/migrate.php).');
        header('Location: ' . $back);
        exit;
    }
    $now = time();
    if ($action === 'schedule') {
        $ids = [];
        if ($s('all') === '1') {
            $ids = array_map('intval', $pdo->query('SELECT id FROM cp_tenants ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        } elseif (isset($_POST['tenants']) && is_array($_POST['tenants'])) {
            foreach ($_POST['tenants'] as $v) {
                if (is_string($v) && ctype_digit($v)) {
                    $ids[] = (int)$v;
                }
            }
        }
        $starts = maint_parse_local($s('starts'), $tz);
        $ends = maint_parse_local($s('ends'), $tz);
        [$err, $clean] = maint_validate([
            'scope' => $s('scope'),
            'starts_at' => $starts ?? 0,
            'ends_at' => $ends ?? 0,
            'announce_from' => $starts !== null ? maint_announce_from($starts, $s('lead'), $now) : 0,
            'message' => $s('message'),
        ], $now);
        if ($starts !== null && $starts < $now && !$err) {
            $clean['starts_at'] = $now; // a start a few minutes ago means now
            $clean['announce_from'] = min($clean['announce_from'], $now);
        }
        if (!$err && !$ids) {
            $err[] = 'Choose at least one business, or tick All businesses.';
        }
        if (!$err) {
            [$e1, $newIds] = maint_schedule($pdo, $ids, $clean, $me, $now);
            if ($e1 !== '') {
                $err[] = $e1;
            }
        }
        if ($err) {
            $_SESSION['maint_form'] = array_intersect_key($_POST, array_flip(['scope', 'starts', 'ends', 'lead', 'message', 'all', 'tenants']));
            flash_set('error', implode(' ', $err));
            header('Location: ' . $back);
            exit;
        }
        flash_set('info', 'Scheduled for ' . count($newIds) . ' business' . (count($newIds) === 1 ? '' : 'es')
            . '. Each one is told now (or at its next check-in, within the hour) and shows the notice to its staff from the time chosen.');
        maint_back_and_nudge($pdo, $back, $ids);
    }
    if (in_array($action, ['end', 'cancel', 'generic'], true)) {
        $id = ctype_digit($s('id')) ? (int)$s('id') : 0;
        [$e1, $tid] = maint_change($pdo, $id, $action, $me, $now);
        if ($e1 !== '') {
            flash_set('error', $e1);
            header('Location: ' . $back);
            exit;
        }
        $done = ['end' => 'Ended. The business comes back online as soon as it checks in (it has been asked to, now).',
            'cancel' => 'Cancelled. The notice disappears at the business\'s next check-in (it has been asked to check in now).',
            'generic' => 'This window now uses the generic message. The business gets it at its next check-in (asked now).'];
        flash_set('info', $done[$action]);
        maint_back_and_nudge($pdo, $back, [$tid]);
    }
    if ($action === 'save_default' || $action === 'revert_default') {
        $e1 = maint_save_default_message($pdo, $action === 'revert_default' ? null : $s('default_message'), $me);
        if ($e1 !== '') {
            flash_set('error', $e1);
            header('Location: ' . $back . '#generic');
            exit;
        }
        flash_set('info', $action === 'revert_default' ? 'The generic message is back to the built-in text.' : 'Generic message saved.');
        // Businesses holding a window that uses the generic message get the new words at their next check-in: ask them now.
        $now2 = time();
        $st = $pdo->prepare("SELECT DISTINCT tenant_id FROM cp_maintenance WHERE state = 'planned' AND ends_at > ? AND message IS NULL");
        $st->execute([$now2]);
        maint_back_and_nudge($pdo, $back . '#generic', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    http_response_code(400);
    echo 'Invalid request.';
    exit;
}

// ── The page ────────────────────────────────────────────────────────────────
$now = time();
$tenants = $pdo->query('SELECT id, name FROM cp_tenants ORDER BY name')->fetchAll();
$names = array_column($tenants, 'name', 'id');
$form = is_array($_SESSION['maint_form'] ?? null) ? $_SESSION['maint_form'] : [];
unset($_SESSION['maint_form']);
$fv = static fn(string $k, string $d = ''): string => isset($form[$k]) && is_string($form[$k]) ? $form[$k] : $d;
$picked = isset($form['tenants']) && is_array($form['tenants']) ? array_map('strval', $form['tenants']) : [];

$view = (string)($_GET['view'] ?? 'current');
$view = in_array($view, ['current', 'ended', 'cancelled', 'all'], true) ? $view : 'current';
$fTenant = isset($_GET['tenant']) && ctype_digit((string)$_GET['tenant']) ? (int)$_GET['tenant'] : 0;
$perIn = (string)($_GET['per'] ?? '10');
$per = in_array($perIn, ['10', '25', '50'], true) ? (int)$perIn : ($perIn === 'all' ? 0 : 10);
$page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$rows = [];
$counts = ['current' => 0, 'ended' => 0, 'cancelled' => 0, 'all' => 0];
$defaultMsg = maint_builtin_message();
$defaultCustom = false;
if ($ready) {
    $defaultMsg = maint_default_message($pdo);
    $defaultCustom = maint_default_is_custom($pdo);
    $sql = 'SELECT m.*, t.name AS tenant_name FROM cp_maintenance m LEFT JOIN cp_tenants t ON t.id = m.tenant_id'
        . ($fTenant > 0 ? ' WHERE m.tenant_id = ?' : '') . ' ORDER BY m.starts_at DESC, m.id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($fTenant > 0 ? [$fTenant] : []);
    foreach ($st->fetchAll() as $r) {
        $state = maint_state($r, $now);
        $bucket = in_array($state, ['upcoming', 'announced', 'offline'], true) ? 'current' : $state;
        $counts[$bucket]++;
        $counts['all']++;
        if ($view === 'all' || $view === $bucket) {
            $r['view_state'] = $state;
            $rows[] = $r;
        }
    }
    if ($view === 'current') {
        usort($rows, static fn($a, $b) => [(int)$a['starts_at'], (int)$a['id']] <=> [(int)$b['starts_at'], (int)$b['id']]); // soonest first
    }
}
$total = count($rows);
$pages = $per > 0 ? max(1, (int)ceil($total / $per)) : 1;
$page = min($page, $pages);
$shown = $per > 0 ? array_slice($rows, ($page - 1) * $per, $per) : $rows;
$link = static function (array $over) use ($view, $fTenant, $perIn, $page): string {
    $q = array_merge(['view' => $view, 'tenant' => $fTenant ?: null, 'per' => $perIn !== '10' ? $perIn : null, 'page' => $page > 1 ? $page : null], $over);
    $q = array_filter($q, static fn($v) => $v !== null && $v !== '');
    return 'maintenance.php' . ($q ? '?' . http_build_query($q) : '');
};
$when = static fn($t): string => $t !== null && (int)$t > 0 ? e(date('D j M Y, H:i', (int)$t)) : view_empty();
$pillFor = ['upcoming' => 'grace', 'announced' => 'trial', 'offline' => 'suspended', 'ended' => 'active', 'cancelled' => 'read_only'];
$scopeShort = ['admin' => 'Staff system', 'site' => 'Guest website', 'both' => 'Staff system and guest website'];
$help = static fn(string $t): string => ' <span class="muted" title="' . e($t) . '" aria-label="' . e($t) . '">(?)</span>';

view_head('Maintenance');
echo '<h1>Maintenance</h1>';
view_flash();
if (!$ready): ?>
<p class="flash flash-error">Maintenance is not set up on this panel yet. Apply migration 014 (<code>php scripts/migrate.php</code>) and reload this page.</p>
<?php view_foot(); exit; endif; ?>
<div class="card">
    <p>Take a business's system offline for planned work. Its staff see a notice ahead of time with the date, the times and the message. During the window the part you choose is offline, and it comes back on by itself when the window ends, even if it cannot reach this panel. <strong>End now</strong> brings it back early.</p>
    <p class="muted">Taking the <strong>staff system</strong> offline stops every staff screen: admin pages, front desk, POS, kitchen and bar screens. Nobody can sign in to work, administrators included. Sales a till saved while offline wait on that till and are sent when the window ends. The guest website keeps taking visitors unless you take it offline too. Times are in the panel's time zone (<?= e($tz) ?>).</p>
</div>

<h2>Schedule a window</h2>
<form method="post" action="maintenance.php" class="card" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="schedule">
    <fieldset><legend>Which businesses<?= $help('One window is saved for each business ticked. If one of them already has a window at that time, nothing is saved.') ?></legend>
        <label class="check"><input type="checkbox" name="all" value="1"<?= $fv('all') === '1' ? ' checked' : '' ?>> All businesses (<?= count($tenants) ?>)</label>
        <?php foreach ($tenants as $t): ?>
            <label class="check"><input type="checkbox" name="tenants[]" value="<?= (int)$t['id'] ?>"<?= in_array((string)$t['id'], $picked, true) ? ' checked' : '' ?>> <?= e($t['name']) ?></label>
        <?php endforeach; ?>
    </fieldset>
    <label>What goes offline
        <select name="scope" required>
            <?php foreach (maint_scopes() as $k => $w): ?>
                <option value="<?= e($k) ?>"<?= $fv('scope', 'admin') === $k ? ' selected' : '' ?>><?= e($w) ?></option>
            <?php endforeach; ?>
        </select></label>
    <label>Starts <input type="datetime-local" name="starts" required value="<?= e($fv('starts')) ?>"></label>
    <label>Ends <small class="muted">(at most 72 hours after the start)</small> <input type="datetime-local" name="ends" required value="<?= e($fv('ends')) ?>"></label>
    <label>Show the notice to staff
        <select name="lead">
            <?php foreach (maint_leads() as $k => $l): ?>
                <option value="<?= e($k) ?>"<?= $fv('lead', '1d') === $k ? ' selected' : '' ?>><?= e($l[0]) ?></option>
            <?php endforeach; ?>
        </select></label>
    <label>Message <small class="muted">(leave empty to use the generic message below; plain text, up to <?= (int)MAINT_MESSAGE_MAX ?> characters)</small>
        <textarea name="message" rows="3" maxlength="<?= (int)MAINT_MESSAGE_MAX ?>" placeholder="<?= e($defaultMsg) ?>"><?= e($fv('message')) ?></textarea></label>
    <button type="submit">Schedule</button>
</form>

<h2>Windows</h2>
<p class="tabs">
    <?php foreach (['current' => 'Upcoming and running', 'ended' => 'Ended', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $w): ?>
        <a href="<?= e($link(['view' => $k, 'page' => null])) ?>"<?= $view === $k ? ' class="active"' : '' ?>><?= e($w) ?> (<?= (int)$counts[$k] ?>)</a>
    <?php endforeach; ?>
</p>
<form method="get" action="maintenance.php" class="inline">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <label>Business <select name="tenant">
        <option value="">All businesses</option>
        <?php foreach ($tenants as $t): ?>
            <option value="<?= (int)$t['id'] ?>"<?= $fTenant === (int)$t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option>
        <?php endforeach; ?>
    </select></label>
    <label>Show <select name="per">
        <?php foreach (['10' => '10', '25' => '25', '50' => '50', 'all' => 'All'] as $k => $w): ?>
            <option value="<?= e($k) ?>"<?= $perIn === $k ? ' selected' : '' ?>><?= e($w) ?></option>
        <?php endforeach; ?>
    </select></label>
    <button type="submit" class="secondary">Show</button>
</form>
<?php if (!$shown): ?>
    <p>No windows here.</p>
<?php else: ?>
<div class="scroll"><table class="list"><thead><tr>
    <?= view_th('Business', 'The customer whose system this window is for.', 'Customer') ?>
    <?= view_th('State', 'Upcoming: saved, notice not showing yet. Announced: staff see the notice. Offline now: inside the window. Ended: the window is over (or End now was pressed). Cancelled: called off before it started.', 'Where it is now') ?>
    <?= view_th('What goes offline', 'Staff system = admin, front desk, POS and kitchen screens. Guest website = the public site.', 'Part of the system') ?>
    <?= view_th('Notice from', 'When the business starts showing the notice to its staff (panel time zone).', 'Staff see it') ?>
    <?= view_th('Window', 'Start and end of the offline time (panel time zone). An ended window shows when it really ended.', 'Offline from - to') ?>
    <?= view_th('Message', 'The words staff (and guests, for the website) see. "Generic" = the generic message below at the time it is sent.', 'Shown to staff') ?>
    <?= view_th('Sent', 'When this window first went out to the business in its licence answer.', 'In its answer') ?>
    <?= view_th('Seen by the hotel', 'When the business first told this panel it holds this window (at the check-in after it was sent, so up to an hour later).', 'Confirmed at') ?>
    <?= view_th('', 'End now brings a running window to an end. Cancel calls off one that has not started.', 'Actions') ?>
</tr></thead><tbody>
<?php foreach ($shown as $r):
    $state = $r['view_state'];
    $btn = static function (string $act, string $label, string $confirm) use ($r): string {
        return '<form method="post" action="maintenance.php" class="inline" data-confirm="' . e($confirm) . '">' . csrf_field()
            . '<input type="hidden" name="action" value="' . e($act) . '"><input type="hidden" name="id" value="' . (int)$r['id'] . '">'
            . '<button type="submit" class="secondary">' . e($label) . '</button></form> ';
    }; ?>
    <tr>
        <td data-label="Business"><?= $r['tenant_name'] !== null ? '<a href="tenant.php?id=' . (int)$r['tenant_id'] . '">' . e($r['tenant_name']) . '</a>' : view_empty() ?></td>
        <td data-label="State"><span class="pill pill-<?= e($pillFor[$state] ?? 'grace') ?>"><?= e(maint_state_words()[$state] ?? $state) ?></span></td>
        <td data-label="What goes offline"><?= e($scopeShort[$r['scope']] ?? $r['scope']) ?></td>
        <td data-label="Notice from" class="nw"><?= $when($r['announce_from']) ?></td>
        <td data-label="Window" class="nw"><?= $when($r['starts_at']) ?><br>to <?= $when($r['ends_at']) ?></td>
        <td data-label="Message"><?= $r['message'] === null ? '<span class="tag">Generic</span>' : view_trunc($r['message']) ?></td>
        <td data-label="Sent" class="nw"><?= $r['sent_at'] !== null ? $when($r['sent_at']) : 'Not yet' ?></td>
        <td data-label="Seen by the hotel" class="nw"><?= $r['hotel_seen_at'] !== null ? $when($r['hotel_seen_at']) : 'Not yet' ?></td>
        <td data-label="Actions">
            <?php if ($state === 'offline') {
                echo $btn('end', 'End now', 'End this window now? The business comes back online at its next check-in (asked now).');
            } elseif ($state === 'upcoming' || $state === 'announced') {
                echo $btn('cancel', 'Cancel', 'Cancel this window? The business is told now.');
            }
            if (in_array($state, ['upcoming', 'announced', 'offline'], true) && $r['message'] !== null) {
                echo $btn('generic', 'Use the generic message', 'Replace this window\'s own message with the generic message?');
            }
            if (!in_array($state, ['upcoming', 'announced', 'offline'], true)) {
                echo view_empty();
            } ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($pages > 1): ?>
<p class="pager">
    <?php if ($page > 1): ?><a href="<?= e($link(['page' => $page - 1])) ?>">Newer page</a><?php endif; ?>
    <span class="muted">Page <?= (int)$page ?> of <?= (int)$pages ?> (<?= (int)$total ?> windows)</span>
    <?php if ($page < $pages): ?><a href="<?= e($link(['page' => $page + 1])) ?>">Next page</a><?php endif; ?>
</p>
<?php endif; ?>
<?php endif; ?>

<h2 id="generic">Generic message<?= $help('Used by every window that has no message of its own, at the time it is sent to the business.') ?></h2>
<div class="card">
    <p class="muted"><?= $defaultCustom ? 'Your own generic message is in use.' : 'The built-in generic message is in use.' ?> Windows without their own message use it; a business holding one gets new words at its next check-in (asked straight away).</p>
    <form method="post" action="maintenance.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_default">
        <label>Message <textarea name="default_message" rows="4" maxlength="<?= (int)MAINT_MESSAGE_MAX ?>" required><?= e($defaultMsg) ?></textarea></label>
        <button type="submit">Save the generic message</button>
    </form>
    <?php if ($defaultCustom): ?>
    <form method="post" action="maintenance.php" class="spaced" data-confirm="Go back to the built-in generic message?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="revert_default">
        <button type="submit" class="secondary">Revert to the generic message</button>
        <span class="muted">Built-in text: <?= e(maint_builtin_message()) ?></span>
    </form>
    <?php endif; ?>
</div>
<script src="assets/billing.js" defer></script>
<?php
view_foot();
