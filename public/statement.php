<?php
// Statement of account for one business (owner 2026-11-09): invoices and the payments against them, running balance,
// what is still open. Download as PDF, email it, or email a payment request for what is owed. Contract: BILLING.md.
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);
$adminId = (int)$admin['id'];

$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$g = static fn(string $k): string => isset($src[$k]) && is_string($src[$k]) ? trim($src[$k]) : '';
$tenantId = ctype_digit($g('tenant')) ? (int)$g('tenant') : 0;
$from = tenant_valid_date($g('from')) ? $g('from') : null;
$until = tenant_valid_date($g('to')) ? $g('to') : null;
$curSel = preg_match('/^[A-Z]{3}$/D', $g('currency')) ? $g('currency') : null; // one statement per currency
$tenant = $tenantId > 0 ? tenant_find($pdo, $tenantId) : null;
if (!$tenant) {
    http_response_code(404);
    view_head('Not found');
    echo '<p>Business not found. <a href="invoices.php?tab=owing">Back to billing</a></p>';
    view_foot();
    exit;
}
$self = 'statement.php?tenant=' . $tenantId . ($from ? '&from=' . $from : '') . ($until ? '&to=' . $until : '') . ($curSel ? '&currency=' . $curSel : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $g('action');
    $back = static function () use ($self): void {
        header('Location: ' . $self);
        exit;
    };
    if ($action === 'pdf') {
        if (!billing_has_pdf()) {
            flash_set('error', 'PDF download is not available yet.');
            $back();
        }
        $stmt = billing_statement($pdo, $tenantId, $from, $until, $curSel);
        billing_stream_pdf(pdf_statement($stmt, billing_company()), pdf_filename('Statement', $tenant['name'] . ' ' . $stmt['to']));
    }
    if ($action === 'send_statement' || $action === 'send_request') {
        if (!billing_has_mail()) {
            flash_set('error', 'Sending email is not available yet.');
            $back();
        }
        $to = billing_parse_emails($g('to_emails'));
        $bad = array_merge(billing_parse_emails($g('to_emails'), true), billing_parse_emails($g('cc'), true));
        if (!$to || $bad) {
            flash_set('error', $bad ? 'Not a valid email address: ' . implode(', ', $bad) : 'Enter at least one email address to send to.');
            $back();
        }
        $opts = ['to' => $to, 'cc' => billing_parse_emails($g('cc')), 'message' => mb_substr($g('message'), 0, 2000)];
        if ($action === 'send_statement') {
            $opts['from'] = $from;
            $opts['until'] = $until;
            $opts['currency'] = $curSel;
            $r = billing_send($pdo, 'statement', $tenantId, $opts, $adminId);
        } else {
            $opts['tenant_id'] = $tenantId;
            $r = billing_send($pdo, 'payment_request', $tenantId, $opts, $adminId);
        }
        flash_set(!empty($r['ok']) ? 'ok' : 'error', !empty($r['ok'])
            ? ($action === 'send_statement' ? 'Statement sent to ' : 'Payment request sent to ') . implode(', ', (array)($r['to'] ?? $to)) . '.'
            : 'Not sent: ' . (string)($r['error'] ?? 'unknown error'));
        $back();
    }
    http_response_code(400);
    echo 'Invalid request.';
    exit;
}

$stmt = billing_statement($pdo, $tenantId, $from, $until, $curSel);
$sum = billing_tenant_summary($pdo, $tenantId);
$cur = $stmt['currency'];
$sumCur = $sum['currency'] === $cur ? $sum : ['overdue' => (float)($sum['other'][$cur]['overdue'] ?? 0)];

view_head('Statement - ' . $tenant['name']);
echo '<p class="muted"><a href="invoices.php?tab=owing">Billing</a> &rsaquo; <a href="tenant.php?id=' . $tenantId . '">' . e($tenant['name']) . '</a></p>';
echo '<h1>Statement: ' . e($tenant['name']) . '</h1>';
view_flash();

echo '<section class="card"><div class="bill-stats">'
    . '<p><span class="muted">Period</span><strong>' . e(billing_date($stmt['from'])) . ' to ' . e(billing_date($stmt['to'])) . '</strong></p>'
    . '<p><span class="muted">Opening balance</span><strong>' . e(billing_money($stmt['opening'], $cur)) . '</strong></p>'
    . '<p><span class="muted">Amount owed</span><strong>' . e(billing_money($stmt['closing'], $cur)) . '</strong></p>'
    . '<p><span class="muted">Overdue now</span><strong' . ($sumCur['overdue'] > BILLING_TOLERANCE ? ' class="bill-overdue"' : '') . '>' . e(billing_money($sumCur['overdue'], $cur)) . '</strong></p>'
    . '</div>'
    . (count($stmt['currencies']) > 1 ? '<p class="flash flash-info">This business has invoices in ' . e(implode(', ', $stmt['currencies'])) . '. Each currency has its own statement: '
        . implode(' ', array_map(static fn($c) => '<a href="statement.php?tenant=' . $tenantId . '&amp;currency=' . e($c) . '">' . e($c) . '</a>', $stmt['currencies'])) . '. A payment request is per currency: send the invoices instead.</p>' : '')
    . '<form method="get" action="statement.php" class="bill-filter"><input type="hidden" name="tenant" value="' . $tenantId . '">'
    . '<label>From<input type="date" name="from" value="' . e((string)$from) . '"></label>'
    . '<label>To<input type="date" name="to" value="' . e((string)$until) . '"></label>'
    . '<button type="submit" class="secondary">Show</button></form>'
    . '<p class="muted">Leave the dates empty for everything up to today. Invoices that were sent or paid count; drafts and void invoices do not. '
    . 'Licence payments recorded on the business page without an invoice are not on the statement.</p>'
    . '<form method="post" action="' . e($self) . '" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="pdf">'
    . '<button type="submit">Download PDF</button></form></section>';

echo '<section class="card"><h2>Account</h2>';
if (!$stmt['lines'] && abs($stmt['opening']) < BILLING_TOLERANCE) {
    echo '<p class="muted">Nothing invoiced in this period.</p>';
} else {
    echo '<div class="scroll"><table><thead><tr>' . view_th('Date', 'Date of the invoice or payment.') . view_th('Type', 'Invoice or payment.')
        . view_th('Ref', 'Invoice number or payment reference.') . view_th('Description', 'What it was.')
        . view_th('Debit', 'Amount invoiced.') . view_th('Credit', 'Amount paid.') . view_th('Balance', 'Running balance owed.') . '</tr></thead><tbody>';
    if (abs($stmt['opening']) >= BILLING_TOLERANCE) {
        echo '<tr><td data-label="Date" class="nw">' . e(billing_date($stmt['from'])) . '</td><td data-label="Type" colspan="5">Opening balance</td>'
            . '<td data-label="Balance" class="nw num">' . e(billing_money($stmt['opening'], $cur)) . '</td></tr>';
    }
    foreach ($stmt['lines'] as $l) {
        echo '<tr><td data-label="Date" class="nw">' . e(billing_date($l['date'])) . '</td>'
            . '<td data-label="Type">' . e(ucfirst($l['type'])) . '</td><td data-label="Ref" class="nw">' . e($l['ref']) . '</td>'
            . '<td data-label="Description">' . e($l['description']) . '</td>'
            . '<td data-label="Debit" class="nw num">' . ($l['debit'] > BILLING_TOLERANCE ? e(billing_money($l['debit'], $cur)) : view_empty()) . '</td>'
            . '<td data-label="Credit" class="nw num">' . ($l['credit'] > BILLING_TOLERANCE ? e(billing_money($l['credit'], $cur)) : view_empty()) . '</td>'
            . '<td data-label="Balance" class="nw num">' . e(billing_money($l['balance'], $cur)) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

echo '<section class="card"><h2>Open invoices</h2>';
if (!$stmt['open_invoices']) {
    echo '<p class="muted">Nothing outstanding.</p>';
} else {
    echo '<div class="scroll"><table><thead><tr>' . view_th('Invoice', 'Invoice number.') . view_th('Date', 'Date of the invoice.')
        . view_th('Due', 'Due date.') . view_th('Total', 'Invoice total.') . view_th('Balance', 'Still to pay.') . '</tr></thead><tbody>';
    foreach ($stmt['open_invoices'] as $d) {
        echo '<tr><td data-label="Invoice"><a href="document.php?id=' . (int)$d['id'] . '">' . e($d['number']) . '</a></td>'
            . '<td data-label="Date" class="nw">' . e(billing_date($d['issue_date'])) . '</td>'
            . '<td data-label="Due" class="nw">' . e(billing_date($d['due_date'])) . ($d['is_overdue'] ? ' <span class="pill pill-suspended">Overdue</span>' : '') . '</td>'
            . '<td data-label="Total" class="nw num">' . e(billing_money((float)$d['total'], $d['currency'])) . '</td>'
            . '<td data-label="Balance" class="nw num">' . e(billing_money($d['balance'], $d['currency'])) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

$defTo = (string)($tenant['billing_email'] ?? '');
echo '<section class="card"><h2>Email</h2>'
    . '<p class="muted">Send the statement (PDF attached), or a payment request listing what is owed and how to pay.</p>'
    . '<form method="post" action="' . e($self) . '">' . csrf_field()
    . '<label>To (separate several with commas)<input type="text" name="to_emails" required maxlength="500" value="' . e($defTo) . '"></label>'
    . '<label>Copy to<input type="text" name="cc" maxlength="500" value="' . e((string)($tenant['cc_emails'] ?? '')) . '"></label>'
    . '<label>Personal note (shown at the top of the email)<textarea name="message" rows="3" maxlength="2000"></textarea></label>'
    . '<button type="submit" name="action" value="send_statement">Send statement</button>'
    . ($sum['owed'] > BILLING_TOLERANCE ? ' <button type="submit" name="action" value="send_request" class="secondary">Send payment request</button>' : '')
    . '</form></section>';
view_foot();
