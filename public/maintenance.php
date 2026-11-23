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
    if ($action === 'schedule' || $action === 'now') {
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
        if ($action === 'now') {
            // Offline now for N minutes/hours (owner 2026-11-24): starts this second, notice from now.
            $win = maint_now_window($s('amount'), $s('unit'), $now);
            $starts = $win[0] ?? null;
            $ends = $win[1] ?? null;
            $lead = 'now';
        } else {
            $starts = maint_parse_local($s('starts'), $tz);
            $ends = maint_parse_local($s('ends'), $tz);
            $lead = $s('lead');
        }
        [$err, $clean] = maint_validate([
            'scope' => $s('scope'),
            'starts_at' => $starts ?? 0,
            'ends_at' => $ends ?? 0,
            'announce_from' => $starts !== null ? maint_announce_from($starts, $lead, $now) : 0,
            'message' => $s('message'),
        ], $now);
        if ($action === 'now' && $starts === null) {
            $err = ['Enter how long it stays offline: a whole number of minutes or hours.'];
        }
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
            $_SESSION['maint_form'] = array_intersect_key($_POST, array_flip(['action', 'scope', 'starts', 'ends', 'lead', 'message', 'all', 'tenants', 'amount', 'unit', 'email_admins']));
            flash_set('error', implode(' ', $err));
            header('Location: ' . $back);
            exit;
        }
        $info = $action === 'now'
            ? 'Offline now for ' . count($newIds) . ' business' . (count($newIds) === 1 ? '' : 'es') . ', until '
                . date('Y-m-d H:i', $clean['ends_at']) . '. Each one is told now and goes offline within seconds (or at its next check-in if it cannot be reached); it comes back on by itself at the end.'
            : 'Scheduled for ' . count($newIds) . ' business' . (count($newIds) === 1 ? '' : 'es')
            . '. Each one is told now (or at its next check-in, within the hour) and shows the notice to its staff from the time chosen.';
        if ($s('email_admins') === '1') {
            // Saved and committed above. Nudge first so each business goes offline (or shows the notice) promptly, then email
            // each business's administrators the matching draft (same path as the compose screen), then one flash for both.
            try {
                backup_nudge_all($pdo, $ids);
            } catch (Throwable $e) {
                error_log('maintenance: nudge: ' . get_class($e) . ': ' . $e->getMessage());
            }
            $draft = $action === 'now' ? 'starting' : 'advance';
            $mailBad = false; // any refused or failed email shows the flash as an error, not info
            foreach ($newIds as $wid) {
                try {
                    $d = maint_mail_draft($pdo, $draft);
                    $res = maint_mail_send($pdo, $wid, $draft, $d['subject'], $d['body'], null, $me, time(), $tz, false);
                    $info .= ' Email to the administrators of ' . ($res['hotel'] !== '' ? $res['hotel'] : 'window ' . $wid) . ': '
                        . ($res['error'] !== '' ? $res['error'] : maint_mail_result_words($res));
                    $mailBad = $mailBad || $res['error'] !== '' || (int)($res['failed'] ?? 0) > 0;
                } catch (Throwable $e) {
                    error_log('maintenance: email window ' . $wid . ': ' . get_class($e) . ': ' . $e->getMessage());
                    $info .= ' Email for window ' . $wid . ': failed (an error on this server, logged).';
                    $mailBad = true;
                }
            }
            flash_set($mailBad ? 'error' : 'info', $info);
            header('Location: ' . $back);
            exit;
        }
        flash_set('info', $info);
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
    if ($action === 'email_send') {
        $id = ctype_digit($s('id')) ? (int)$s('id') : 0;
        $draft = $s('draft');
        $people = isset($_POST['people']) && is_array($_POST['people']) ? array_values(array_filter($_POST['people'], 'is_string')) : [];
        try {
            $res = maint_mail_send($pdo, $id, $draft, $s('subject'), $s('body'), $people, $me, $now, $tz, $s('again') === '1');
        } catch (Throwable $e) {
            error_log('maintenance: email window ' . $id . ': ' . get_class($e) . ': ' . $e->getMessage());
            $res = ['error' => 'The email could not be sent: an error on this server (logged). Check the Emailed column before trying again.'];
        }
        if ($res['error'] !== '') {
            $_SESSION['maint_mail_form'] = ['id' => $id, 'draft' => $draft, 'subject' => $s('subject'), 'body' => $s('body'), 'people' => $people];
            flash_set('error', $res['error']);
            header('Location: maintenance.php?email=' . $id . '&draft=' . rawurlencode($draft));
            exit;
        }
        flash_set($res['failed'] > 0 ? 'error' : 'info', 'Email about the window for ' . $res['hotel'] . ': ' . maint_mail_result_words($res));
        header('Location: ' . $back);
        exit;
    }
    if ($action === 'draft_save' || $action === 'draft_revert') {
        $key = $s('draft');
        $e1 = maint_mail_save_draft($pdo, $key, $action === 'draft_revert' ? null : $s('subject'), $action === 'draft_revert' ? null : $s('body'), $me);
        $anchor = '#draft-' . (isset(maint_mail_drafts()[$key]) ? $key : 'list');
        flash_set($e1 !== '' ? 'error' : 'info', $e1 !== '' ? $e1 : ($action === 'draft_revert' ? 'The draft is back to the built-in text.' : 'Draft saved.'));
        header('Location: ' . $back . $anchor);
        exit;
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
$emailed = $ready && $shown ? maint_mail_sent_summary($pdo, array_column($shown, 'tenant_id')) : [];
$link = static function (array $over) use ($view, $fTenant, $perIn, $page): string {
    $q = array_merge(['view' => $view, 'tenant' => $fTenant ?: null, 'per' => $perIn !== '10' ? $perIn : null, 'page' => $page > 1 ? $page : null], $over);
    $q = array_filter($q, static fn($v) => $v !== null && $v !== '');
    return 'maintenance.php' . ($q ? '?' . http_build_query($q) : '');
};
$when = static fn($t): string => $t !== null && (int)$t > 0 ? e(date('D j M Y, H:i', (int)$t)) : view_empty();
$pillFor = ['upcoming' => 'grace', 'announced' => 'trial', 'offline' => 'suspended', 'ended' => 'active', 'cancelled' => 'read_only'];
$scopeShort = ['admin' => 'Staff system', 'site' => 'Guest website', 'both' => 'Staff system and guest website'];
$help = static fn(string $t): string => ' <span class="q" tabindex="0" role="note" title="' . e($t) . '" data-help="' . e($t) . '" aria-label="' . e($t) . '">?</span>';

view_head('Maintenance');
echo view_page_head('Maintenance');
view_flash();
if (!$ready): ?>
<p class="flash flash-error">Maintenance is not set up on this panel yet. Apply migration 014 (<code>php scripts/migrate.php</code>) and reload this page.</p>
<?php view_foot(); exit; endif;

// ── Email the business's administrators about one window (?email=ID) ───────
if (isset($_GET['email'])):
    $mid = ctype_digit((string)$_GET['email']) ? (int)$_GET['email'] : 0;
    [$merr, $mw, $mt, $mstaff, $mcands] = maint_mail_context($pdo, $mid, $now);
    $mform = is_array($_SESSION['maint_mail_form'] ?? null) && (int)($_SESSION['maint_mail_form']['id'] ?? 0) === $mid ? $_SESSION['maint_mail_form'] : null;
    unset($_SESSION['maint_mail_form']);
    $drafts = maint_mail_drafts();
    $mdraft = (string)($_GET['draft'] ?? '');
    if (!isset($drafts[$mdraft])) {
        $mdraft = $mw !== null ? maint_mail_default_draft(maint_state($mw, $now)) : 'advance';
    }
    ?>
<p><a href="maintenance.php">Back to Maintenance</a></p>
<?php if ($merr !== ''): ?>
    <p class="flash flash-error"><?= e($merr) ?></p>
<?php else:
    $mstate = maint_state($mw, $now);
    // The draft as written (placeholders in): they are filled once, when it is sent.
    $dtext = maint_mail_draft($pdo, $mdraft);
    $msubject = $mform !== null && (string)($mform['draft'] ?? '') === $mdraft ? (string)$mform['subject'] : $dtext['subject'];
    $mbody = $mform !== null && (string)($mform['draft'] ?? '') === $mdraft ? (string)$mform['body'] : $dtext['body'];
    $mpicked = $mform !== null ? array_map('intval', (array)($mform['people'] ?? [])) : null;
    $msent = maint_mail_sent_to($pdo, $mid, $mdraft);
    $mticks = maint_mail_default_ticks($mcands, $msent, $mpicked);
    $hasAdmin = (bool)array_filter($mcands, static fn($c) => $c['is_admin']); ?>
<h2>Email about the window for <?= e($mt['name']) ?></h2>
<div class="card">
    <p><span class="pill pill-<?= e($pillFor[$mstate] ?? 'grace') ?>"><?= e(maint_state_words()[$mstate] ?? $mstate) ?></span>
        <?= e($scopeShort[$mw['scope']] ?? $mw['scope']) ?>, <?= $when($mw['starts_at']) ?> to <?= $when($mw['ends_at']) ?> (<?= e($tz) ?>).</p>
    <?php if (!$mcands || !$hasAdmin): ?>
        <p class="flash flash-error"><?= e(maint_mail_none_reason((string)$mt['name'], $mstaff)) ?></p>
    <?php endif; ?>
    <form method="get" action="maintenance.php" class="inline">
        <input type="hidden" name="email" value="<?= (int)$mid ?>">
        <label>Draft<?= $help('Each draft fills in this window\'s business, date, times, what goes offline and message. Edit the drafts at the bottom of the Maintenance page.') ?>
            <select name="draft">
                <?php foreach ($drafts as $k => $d): ?>
                    <option value="<?= e($k) ?>"<?= $mdraft === $k ? ' selected' : '' ?>><?= e($d['label']) ?></option>
                <?php endforeach; ?>
            </select></label>
        <button type="submit" class="secondary">Use this draft</button>
    </form>
</div>
<?php if ($mcands): ?>
<form method="post" action="maintenance.php" class="card" autocomplete="off" data-confirm="Send this email now, one to each person ticked?">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="email_send">
    <input type="hidden" name="id" value="<?= (int)$mid ?>">
    <input type="hidden" name="draft" value="<?= e($mdraft) ?>">
    <fieldset><legend>Send to<?= $help('Active staff of this business that have an email in their own system (from its last staff list). Administrators are ticked, except those this draft already went to about this window ("Sent"); tick others only when they need to know. At most ' . MAINT_MAIL_CAP . ' people; each gets their own email.') ?></legend>
        <?php foreach ($mcands as $c): ?>
            <label class="check"><input type="checkbox" name="people[]" value="<?= (int)$c['pms_user_id'] ?>"<?= $mticks[$c['pms_user_id']] ? ' checked' : '' ?>>
                <?= e($c['name']) ?> <span class="tag"><?= e($c['is_admin'] ? 'Administrator' : ($c['role'] !== '' ? $c['role'] : 'Staff')) ?></span> <span class="muted"><?= e($c['email']) ?></span><?php
                $sk = strtolower($c['email']);
                if (isset($msent[$sk])): ?> <span class="tag">Sent <?= e(date('D j M Y, H:i', (int)strtotime($msent[$sk]))) ?></span><?php endif; ?></label>
        <?php endforeach; ?>
    </fieldset>
    <label>Subject <input type="text" name="subject" required maxlength="<?= (int)MAINT_MAIL_SUBJECT_MAX ?>" value="<?= e($msubject) ?>"></label>
    <p class="muted">Filled in when it is sent: <?php $ph = []; foreach (maint_mail_placeholders() as $k => $w) { $ph[] = '<code>' . e($k) . '</code> ' . e($w); } echo implode('; ', $ph); ?>.</p>
    <label>Message <small class="muted">(plain text; a blank line starts a new paragraph; your company details and signature are added below it)</small>
        <textarea name="body" rows="12" required maxlength="<?= (int)MAINT_MAIL_BODY_MAX ?>"><?= e($mbody) ?></textarea></label>
    <label class="check"><input type="checkbox" name="again" value="1"> Send it again<?= $help('Only needed when this same draft was sent about this window less than a minute ago: it stops a double click sending everything twice.') ?></label>
    <button type="submit">Send</button>
</form>
<?php endif; ?>
<?php endif; ?>
<script src="assets/billing.js" defer></script>
<?php view_foot(); exit; endif; ?>
<div class="card">
    <p>Take a business's system offline for planned work. Its staff see a notice ahead of time with the date, the times and the message. During the window the part you choose is offline, and it comes back on by itself when the window ends, even if it cannot reach this panel. <strong>End now</strong> brings it back early.</p>
    <p class="muted">Taking the <strong>staff system</strong> offline stops every staff screen: admin pages, front desk, POS, kitchen and bar screens. Nobody can sign in to work, administrators included. Sales a till saved while offline wait on that till and are sent when the window ends. The guest website keeps taking visitors unless you take it offline too. Times are in the panel's time zone (<?= e($tz) ?>).</p>
</div>

<?php
// The form that was sent back with an error keeps its choices; the other one starts empty.
$wasNow = $fv('action') === 'now';
$pickedNow = $wasNow ? $picked : [];
$picked = $wasNow ? [] : $picked;
$fvNow = static fn(string $k, string $d = ''): string => $wasNow ? $fv($k, $d) : $d;
$fvSched = static fn(string $k, string $d = ''): string => $wasNow ? $d : $fv($k, $d);
?>
<h2>Offline now</h2>
<form method="post" action="maintenance.php" class="card" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="now">
    <p class="muted">Starts straight away and comes back on by itself after the time you enter (at most 72 hours). Staff see the notice from now. <strong>End now</strong> in the list below brings it back early.</p>
    <fieldset><legend>Which businesses<?= $help('One window is saved for each business ticked. If one of them already has a window at that time, nothing is saved.') ?></legend>
        <label class="check"><input type="checkbox" name="all" value="1"<?= $fvNow('all') === '1' ? ' checked' : '' ?>> All businesses (<?= count($tenants) ?>)</label>
        <?php foreach ($tenants as $t): ?>
            <label class="check"><input type="checkbox" name="tenants[]" value="<?= (int)$t['id'] ?>"<?= in_array((string)$t['id'], $pickedNow, true) ? ' checked' : '' ?>> <?= e($t['name']) ?></label>
        <?php endforeach; ?>
    </fieldset>
    <label>What goes offline
        <select name="scope" required>
            <?php foreach (maint_scopes() as $k => $w): ?>
                <option value="<?= e($k) ?>"<?= $fvNow('scope', 'admin') === $k ? ' selected' : '' ?>><?= e($w) ?></option>
            <?php endforeach; ?>
        </select></label>
    <label>For <input type="number" name="amount" min="1" max="4320" step="1" required inputmode="numeric" value="<?= e($fvNow('amount', '30')) ?>"></label>
    <label>Unit
        <select name="unit">
            <?php foreach (['minutes' => 'Minutes', 'hours' => 'Hours'] as $k => $w): ?>
                <option value="<?= e($k) ?>"<?= $fvNow('unit', 'minutes') === $k ? ' selected' : '' ?>><?= e($w) ?></option>
            <?php endforeach; ?>
        </select></label>
    <label>Message <small class="muted">(leave empty to use the generic message below; plain text, up to <?= (int)MAINT_MESSAGE_MAX ?> characters)</small>
        <textarea name="message" rows="3" maxlength="<?= (int)MAINT_MESSAGE_MAX ?>" placeholder="<?= e($defaultMsg) ?>"><?= e($fvNow('message')) ?></textarea></label>
    <label class="check"><input type="checkbox" name="email_admins" value="1"<?= $fvNow('email_admins') === '1' ? ' checked' : '' ?>> Email the business's administrators now<?= $help('After saving, sends the "Starting now" draft (Email drafts, below) to each business\'s active administrators that have an email in their own system.') ?></label>
    <button type="submit">Go offline now</button>
</form>

<h2>Schedule a window</h2>
<form method="post" action="maintenance.php" class="card" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="schedule">
    <fieldset><legend>Which businesses<?= $help('One window is saved for each business ticked. If one of them already has a window at that time, nothing is saved.') ?></legend>
        <label class="check"><input type="checkbox" name="all" value="1"<?= $fvSched('all') === '1' ? ' checked' : '' ?>> All businesses (<?= count($tenants) ?>)</label>
        <?php foreach ($tenants as $t): ?>
            <label class="check"><input type="checkbox" name="tenants[]" value="<?= (int)$t['id'] ?>"<?= in_array((string)$t['id'], $picked, true) ? ' checked' : '' ?>> <?= e($t['name']) ?></label>
        <?php endforeach; ?>
    </fieldset>
    <label>What goes offline
        <select name="scope" required>
            <?php foreach (maint_scopes() as $k => $w): ?>
                <option value="<?= e($k) ?>"<?= $fvSched('scope', 'admin') === $k ? ' selected' : '' ?>><?= e($w) ?></option>
            <?php endforeach; ?>
        </select></label>
    <label>Starts <input type="datetime-local" name="starts" required value="<?= e($fvSched('starts')) ?>"></label>
    <label>Ends <small class="muted">(at most 72 hours after the start)</small> <input type="datetime-local" name="ends" required value="<?= e($fvSched('ends')) ?>"></label>
    <label>Show the notice to staff
        <select name="lead">
            <?php foreach (maint_leads() as $k => $l): ?>
                <option value="<?= e($k) ?>"<?= $fvSched('lead', '1d') === $k ? ' selected' : '' ?>><?= e($l[0]) ?></option>
            <?php endforeach; ?>
        </select></label>
    <label>Message <small class="muted">(leave empty to use the generic message below; plain text, up to <?= (int)MAINT_MESSAGE_MAX ?> characters)</small>
        <textarea name="message" rows="3" maxlength="<?= (int)MAINT_MESSAGE_MAX ?>" placeholder="<?= e($defaultMsg) ?>"><?= e($fvSched('message')) ?></textarea></label>
    <label class="check"><input type="checkbox" name="email_admins" value="1"<?= $fvSched('email_admins') === '1' ? ' checked' : '' ?>> Email the business's administrators now<?= $help('After saving, sends the "Advance notice" draft (Email drafts, below) to each business\'s active administrators that have an email in their own system.') ?></label>
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
    <?= view_th('Emailed', 'When an email about this window last went to the business\'s staff from this page, and how many emails went in all (every draft, test-mode sends included).', 'To its staff') ?>
    <?= view_th('', 'End now brings a running window to an end. Cancel calls off one that has not started. Email admins writes to the business\'s administrators (upcoming, running, or ended in the last 7 days).', 'Actions') ?>
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
        <td data-label="Emailed" class="nw"><?= isset($emailed[(int)$r['id']]) ? e(date('D j M Y, H:i', (int)strtotime($emailed[(int)$r['id']]['last']))) . ' (' . (int)$emailed[(int)$r['id']]['n'] . ')' : 'Not yet' ?></td>
        <td data-label="Actions">
            <?php if ($state === 'offline') {
                echo $btn('end', 'End now', 'End this window now? The business comes back online at its next check-in (asked now).');
            } elseif ($state === 'upcoming' || $state === 'announced') {
                echo $btn('cancel', 'Cancel', 'Cancel this window? The business is told now.');
            }
            if (in_array($state, ['upcoming', 'announced', 'offline'], true) && $r['message'] !== null) {
                echo $btn('generic', 'Use the generic message', 'Replace this window\'s own message with the generic message?');
            }
            $canMail = maint_mail_can_email($r, $now) === '';
            if ($canMail) {
                echo '<a href="maintenance.php?email=' . (int)$r['id'] . '">Email admins</a>';
            }
            if (!$canMail && !in_array($state, ['upcoming', 'announced', 'offline'], true)) {
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

<h2 id="draft-list">Email drafts<?= $help('The ready-made emails for "Email admins" and for "Email the business\'s administrators now". The words in braces are filled in from the window when the email is written.') ?></h2>
<div class="card">
    <p class="muted">Filled in from the window: <?php $ph = []; foreach (maint_mail_placeholders() as $k => $w) { $ph[] = '<code>' . e($k) . '</code> ' . e($w); } echo implode('; ', $ph); ?>. Plain text only; a blank line starts a new paragraph.</p>
</div>
<?php foreach (maint_mail_drafts() as $dk => $bd):
    $cur = maint_mail_draft($pdo, $dk); ?>
<div class="card" id="draft-<?= e($dk) ?>">
    <h3><?= e($bd['label']) ?> <span class="tag"><?= $cur['custom'] ? 'Your own words' : 'Built-in' ?></span></h3>
    <form method="post" action="maintenance.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="draft_save">
        <input type="hidden" name="draft" value="<?= e($dk) ?>">
        <label>Subject <input type="text" name="subject" required maxlength="<?= (int)MAINT_MAIL_SUBJECT_MAX ?>" value="<?= e($cur['subject']) ?>"></label>
        <label>Message <textarea name="body" rows="8" required maxlength="<?= (int)MAINT_MAIL_BODY_MAX ?>"><?= e($cur['body']) ?></textarea></label>
        <button type="submit">Save this draft</button>
    </form>
    <?php if ($cur['custom']): ?>
    <form method="post" action="maintenance.php" class="spaced" data-confirm="Go back to the built-in words for this draft?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="draft_revert">
        <input type="hidden" name="draft" value="<?= e($dk) ?>">
        <button type="submit" class="secondary">Revert to the built-in draft</button>
    </form>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<script src="assets/billing.js" defer></script>
<?php
view_foot();
