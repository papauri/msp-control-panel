<?php
// Automations (BILLING.md Part 2): what each automated email does, its mode (off / ask me first / send automatically), the last
// 10 that went out, and "Run the daily job now" (a dry-run preview first, then the real run).
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);
$adminId = (int)$admin['id'];
require_once __DIR__ . '/../app/billing_mail.php';
foreach (['clients.php', 'recurring.php'] as $f) {
    if (is_file(__DIR__ . '/../app/' . $f)) {
        require_once __DIR__ . '/../app/' . $f;
    }
}

$modeWords = ['off' => 'Off', 'approve' => 'Ask me first (waits in the Outbox)', 'auto' => 'Send automatically'];
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'save') {
        $in = isset($_POST['mode']) && is_array($_POST['mode']) ? $_POST['mode'] : [];
        $errors = [];
        $changed = 0;
        $before = automation_modes();
        foreach (array_keys(automation_defaults()) as $key) {
            if (!isset($in[$key]) || !is_string($in[$key])) {
                continue;
            }
            $err = automation_mode_save($pdo, $key, $in[$key], $adminId);
            if ($err !== '') {
                $errors[] = $err;
            } elseif ($before[$key] !== $in[$key]) {
                $changed++;
            }
        }
        flash_set($errors ? 'error' : 'ok', $errors ? implode(' ', array_unique($errors)) : ($changed ? 'Automations saved.' : 'Nothing changed.'));
        header('Location: automations.php');
        exit;
    }
    if ($action === 'preview') {
        $preview = billing_daily($pdo, date('Y-m-d'), true);
    } elseif ($action === 'run') {
        $r = billing_daily($pdo, date('Y-m-d'), false);
        audit_log($pdo, $adminId, 'billing_daily_run', null, null, ['summary' => $r['summary'], 'counts' => $r['counts'], 'ok' => $r['ok']]);
        flash_set($r['ok'] ? 'ok' : 'error', $r['summary']);
        header('Location: automations.php');
        exit;
    } else {
        http_response_code(400);
        echo 'Invalid request.';
        exit;
    }
}

$modes = automation_modes();
$counts = outbox_counts($pdo);
$resultWords = ['would_create' => 'Would create', 'would_send' => 'Would send', 'would_queue' => 'Would wait in the Outbox',
    'duplicate' => 'Already handled', 'sent' => 'Sent', 'pending' => 'Waiting in the Outbox', 'failed' => 'Failed', 'draft' => 'Draft',
    'off' => 'Off'];

$recentList = static function (array $rows): string {
    if (!$rows) {
        return '<p class="muted au-none">None sent yet.</p>';
    }
    $h = '<ul class="au-sent">';
    foreach ($rows as $r) {
        $h .= '<li><span class="nw">' . e(billing_date((string)$r['created_at'])) . '</span> '
            . (!empty($r['document_id']) ? '<a href="document.php?id=' . (int)$r['document_id'] . '">' . e((string)$r['subject']) . '</a>' : e((string)$r['subject']))
            . ' <span class="muted">to ' . e((string)$r['to_email']) . ($r['status'] === 'test' ? ' (test mode)' : '') . '</span></li>';
    }
    return $h . '</ul>';
};

view_head('Automations');
echo '<h1>Automations</h1>';
view_flash();
echo '<p class="muted">Emails the panel prepares by itself. For each one choose <strong>Off</strong>, <strong>Ask me first</strong> (it waits in the '
    . '<a href="outbox.php">Outbox</a> for one click) or <strong>Send automatically</strong>. Nothing is ever sent twice for the same payment, '
    . 'invoice or reminder day. While MAIL_TEST_TO is set in .env every email goes only to that address.</p>';
if ($counts['pending'] + $counts['failed'] > 0) {
    echo '<p class="flash flash-info"><a href="outbox.php">' . (int)($counts['pending'] + $counts['failed']) . ' email(s) waiting in the Outbox.</a></p>';
}

echo '<form method="post" action="automations.php">' . csrf_field() . '<input type="hidden" name="action" value="save">';
foreach (automation_catalog() as $key => $a) {
    echo '<section class="card au-auto"><div class="au-row"><div class="au-desc"><h2>' . e($a['title']) . '</h2>'
        . '<p>' . e($a['what']) . '</p><p class="muted">When: ' . e($a['when']) . '</p></div>'
        . '<label class="au-mode">Mode<select name="mode[' . e($key) . ']">';
    foreach ($modeWords as $m => $w) {
        echo '<option value="' . e($m) . '"' . ($modes[$key] === $m ? ' selected' : '') . '>' . e($w) . '</option>';
    }
    echo '</select></label></div>'
        . '<details><summary>Last 10 sent</summary>' . $recentList(automation_recent($pdo, $a['kind'], 10)) . '</details></section>';
}
echo '<section class="card au-auto"><div class="au-row"><div class="au-desc"><h2>Regular (recurring) invoices</h2>'
    . '<p>Creates the invoice for each recurring schedule when it falls due, and emails it with the words "your regular invoice for ...".</p>'
    . '<p class="muted">When: each day the daily job runs. Each schedule has its own mode (send automatically, ask me first, or leave as a draft)'
    . (is_file(__DIR__ . '/recurring.php') ? ': <a href="recurring.php">Recurring invoices</a>' : '') . '.</p></div>'
    . '<p class="au-mode muted">Set per schedule</p></div>'
    . '<details><summary>Last 10 sent</summary>' . $recentList(automation_recent($pdo, 'recurring_invoice', 10)) . '</details></section>';
echo '<p><button type="submit">Save automations</button></p></form>';

echo '<section class="card"><h2>Daily job</h2>'
    . '<p class="muted">Runs once a day from cron (scripts/billing-daily.php): recurring invoices first, then the reminders due today, then the free-trial emails (ending soon, ended). '
    . 'You can run it now; you will see what it would do before anything happens.</p>';
if ($preview === null) {
    echo '<form method="post" action="automations.php">' . csrf_field() . '<input type="hidden" name="action" value="preview">'
        . '<button type="submit" class="secondary">Run the daily job now&hellip;</button></form>';
} else {
    echo '<div class="au-result"><p><strong>Preview (nothing has been done yet):</strong> ' . e($preview['summary']) . '</p>';
    foreach (['recurring' => 'Recurring invoices', 'reminders' => 'Reminders', 'trials' => 'Free trials'] as $k => $label) {
        echo '<h3>' . e($label) . '</h3>';
        if (empty($preview[$k])) {
            echo '<p class="muted">Nothing due today.</p>';
            continue;
        }
        echo '<ul>';
        foreach ($preview[$k] as $it) {
            echo '<li>' . e($it['label']) . ': <strong>' . e($resultWords[$it['result']] ?? $it['result']) . '</strong>'
                . ($it['detail'] !== '' ? ' <span class="muted">(' . e($it['detail']) . ')</span>' : '') . '</li>';
        }
        echo '</ul>';
    }
    foreach ($preview['notes'] as $n) {
        echo '<p class="muted">' . e($n) . '</p>';
    }
    foreach ($preview['failures'] as $f) {
        echo '<p class="flash flash-error">' . e($f) . '</p>';
    }
    echo '<div class="au-actions"><form method="post" action="automations.php" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="run">'
        . '<button type="submit">Run it now</button></form> <a href="automations.php">Cancel</a></div></div>';
}
echo '</section>';
view_foot();
