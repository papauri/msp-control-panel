<?php
// Outbox (BILLING.md Part 2): automated emails waiting for the owner (Send / Skip, optional personal note), failed ones to
// retry, and the history of what went out automatically or by hand from here.
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);
$adminId = (int)$admin['id'];
require_once __DIR__ . '/../app/billing_mail.php';
if (is_file(__DIR__ . '/../app/clients.php')) {
    require_once __DIR__ . '/../app/clients.php';
}

$tab = isset($_GET['tab']) && $_GET['tab'] === 'history' ? 'history' : 'waiting';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $id = isset($_POST['id']) && is_string($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : 0;
    $note = isset($_POST['note']) && is_string($_POST['note']) ? mb_substr(trim($_POST['note']), 0, 2000) : '';
    if ($id <= 0 || !in_array($action, ['send', 'skip'], true)) {
        http_response_code(400);
        echo 'Invalid request.';
        exit;
    }
    $r = outbox_decide($pdo, $id, $action, $adminId, $note);
    if ($r['ok']) {
        flash_set('ok', $action === 'skip' ? 'Skipped: nothing was sent.'
            : 'Sent to ' . implode(', ', (array)($r['to'] ?? [])) . (!empty($r['test']) ? ' (test mode: it went to the MAIL_TEST_TO address only).' : '.'));
    } else {
        flash_set('error', ($action === 'skip' ? 'Not skipped: ' : 'Not sent: ') . $r['error']);
    }
    header('Location: outbox.php');
    exit;
}

$counts = outbox_counts($pdo);
$hasClientPage = is_file(__DIR__ . '/client.php');
$clientLink = static function (array $o) use ($hasClientPage): string {
    $name = (string)($o['client_name'] ?? '');
    if ($name === '') {
        return view_empty();
    }
    return $hasClientPage && !empty($o['client_id']) ? '<a href="client.php?id=' . (int)$o['client_id'] . '">' . e($name) . '</a>' : e($name);
};
$docLink = static function (array $o): string {
    if (empty($o['document_id']) || (string)($o['document_number'] ?? '') === '') {
        return '';
    }
    $word = ($o['document_kind'] ?? '') === 'quote' ? 'Quotation ' : 'Invoice ';
    return '<a href="document.php?id=' . (int)$o['document_id'] . '">' . e($word . $o['document_number']) . '</a>';
};
$statusPill = static function (array $o): string {
    $s = (string)$o['status'];
    $state = outbox_send_state($o);
    $cls = ['pending' => 'pill-grace', 'sent' => 'pill-active', 'failed' => 'pill-suspended', 'skipped' => 'pill-read_only'][$s] ?? '';
    $label = ['pending' => 'Waiting', 'sent' => 'Sent', 'failed' => 'Failed', 'skipped' => 'Skipped'][$s] ?? $s;
    if ($state !== '') {
        $cls = $state === 'sending' ? 'pill-grace' : 'pill-suspended';
        $label = $state === 'sending' ? 'Sending' : 'Interrupted';
    }
    return '<span class="pill ' . $cls . '">' . e($label) . '</span>';
};

view_head('Outbox');
echo view_page_head('Outbox', 'Emails the automations prepared. Those set to "ask me first" wait here until you send or skip them; '
    . 'automatic ones that failed wait here to be retried. Nothing here is ever sent twice. '
    . '<a href="automations.php">Automations</a> sets what is automatic.');
view_flash();
echo '<nav class="tabs"><a href="outbox.php"' . ($tab === 'waiting' ? ' class="active"' : '') . '>Waiting (' . (int)($counts['pending'] + $counts['failed']) . ')</a>'
    . '<a href="outbox.php?tab=history"' . ($tab === 'history' ? ' class="active"' : '') . '>History</a></nav>';

if ($tab === 'waiting') {
    $rows = outbox_list($pdo, ['pending', 'failed'], 50, true);
    if (!$rows) {
        echo '<section class="card"><p class="muted">Nothing is waiting. Every automated email has been sent or skipped.</p></section>';
    }
    foreach ($rows as $o) {
        $p = outbox_preview($pdo, $o);
        $failed = $o['status'] === 'failed';
        $state = outbox_send_state($o);
        echo '<section class="card au-item">'
            . '<div class="au-head"><h2>' . e(billing_mail_kind_label((string)$o['kind'])) . '</h2> ' . $statusPill($o) . '</div>'
            . '<p class="au-meta"><span>' . e((string)$o['reason']) . '</span><span>Client: ' . $clientLink($o) . '</span>'
            . ($docLink($o) !== '' ? '<span>' . $docLink($o) . '</span>' : '')
            . '<span class="muted">Prepared ' . e(billing_date((string)$o['created_at'])) . ' ' . e(substr((string)$o['created_at'], 11, 5)) . '</span></p>';
        if ($state === 'sending') {
            echo '<p class="muted">This email is being sent right now. Refresh the page in a minute.</p>';
        } elseif ($state === 'interrupted') {
            echo '<p class="flash flash-error">The last send was interrupted before it finished, so this email may or may not have gone out. '
                . 'Check your Sent mail first: if it is there, skip this one; if not, retry it.</p>';
        } elseif ($failed && trim((string)$o['error']) !== '') {
            echo '<p class="flash flash-error">Last attempt failed: ' . e((string)$o['error']) . '</p>';
        }
        if ($p['ok']) {
            echo '<dl class="au-preview">'
                . '<dt>Subject</dt><dd>' . e($p['subject']) . '</dd>'
                . '<dt>To</dt><dd>' . ($p['to'] ? e(implode(', ', $p['to'])) : '<span class="bill-overdue">No email address on the client yet: add one, then send.</span>') . '</dd>'
                . ($p['cc'] ? '<dt>Copy</dt><dd>' . e(implode(', ', $p['cc'])) . '</dd>' : '')
                . '<dt>Attached</dt><dd>' . ($p['attachments'] ? e(implode(', ', $p['attachments'])) : 'Nothing') . '</dd>'
                . '</dl><pre class="au-text">' . e(implode("\n", $p['lines'])) . "\n&hellip;</pre>";
        } else {
            echo '<p class="flash flash-error">Cannot be sent as it stands: ' . e($p['error']) . '</p>';
        }
        if ($state === 'sending') {
            echo '</section>';
            continue;
        }
        echo '<form method="post" action="outbox.php" class="au-decide">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$o['id'] . '">'
            . '<label>Personal note (optional; shown at the top of the email, or kept as the reason if you skip)'
            . '<textarea name="note" rows="2" maxlength="2000"></textarea></label>'
            . '<div class="au-actions"><button type="submit" name="action" value="send">' . ($failed ? 'Retry send' : 'Send') . '</button>'
            . '<button type="submit" name="action" value="skip" class="secondary">Skip</button></div></form>'
            . '</section>';
    }
} else {
    $rows = outbox_list($pdo, ['sent', 'skipped', 'failed'], 200);
    echo '<section class="card">';
    if (!$rows) {
        echo '<p class="muted">No automated emails yet.</p>';
    } else {
        echo '<div class="scroll"><table><thead><tr>' . view_th('Prepared', 'When the automation prepared it.') . view_th('Email', 'What kind of email.')
            . view_th('Client', 'Who it is for.') . view_th('Why', 'What triggered it.') . view_th('Status', 'Sent, skipped or failed.')
            . view_th('Decided', 'When it was sent or skipped, and how.') . '</tr></thead><tbody>';
        foreach ($rows as $o) {
            $how = $o['decided_at'] ? e(billing_date((string)$o['decided_at']) . ' ' . substr((string)$o['decided_at'], 11, 5)) . ' '
                . ($o['decided_by'] ? '<span class="muted">by hand</span>' : '<span class="muted">automatically</span>') : view_empty();
            echo '<tr><td data-label="Prepared" class="nw">' . e(billing_date((string)$o['created_at'])) . '</td>'
                . '<td data-label="Email">' . e(billing_mail_kind_label((string)$o['kind'])) . ($docLink($o) !== '' ? '<br><small>' . $docLink($o) . '</small>' : '') . '</td>'
                . '<td data-label="Client">' . $clientLink($o) . '</td>'
                . '<td data-label="Why">' . e((string)$o['reason']) . (trim((string)$o['error']) !== '' ? '<br><small class="muted">' . e((string)$o['error']) . '</small>' : '') . '</td>'
                . '<td data-label="Status">' . $statusPill($o) . '</td>'
                . '<td data-label="Decided" class="nw">' . $how . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
}
view_foot();
