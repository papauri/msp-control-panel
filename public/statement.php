<?php
// Statement of account for one client (owner 2026-11-09; per client since Part 2): invoices and the payments against
// them, running balance, what is still open, in one currency at a time. Download as PDF, email it, or email a payment
// request for what is owed. ?client= (or ?tenant= for a hosted business: its client). Contract: BILLING.md.
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);
$adminId = (int)$admin['id'];

$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$g = static fn(string $k): string => isset($src[$k]) && is_string($src[$k]) ? trim($src[$k]) : '';
$clientId = ctype_digit($g('client')) ? (int)$g('client') : 0;
if ($clientId === 0 && ctype_digit($g('tenant'))) {
    $tc = client_for_tenant($pdo, (int)$g('tenant'));
    $clientId = $tc ? (int)$tc['id'] : 0;
}
$from = tenant_valid_date($g('from')) ? $g('from') : null;
$until = tenant_valid_date($g('to')) ? $g('to') : null;
$curSel = preg_match('/^[A-Z]{3}$/D', $g('currency')) ? $g('currency') : null; // one statement per currency
$client = $clientId > 0 ? client_find($pdo, $clientId) : null;
if (!$client) {
    http_response_code(404);
    view_head('Not found');
    echo '<p>Client not found. <a href="invoices.php?tab=owing">Back to billing</a></p>';
    view_foot();
    exit;
}
$self = 'statement.php?client=' . $clientId . ($from ? '&from=' . $from : '') . ($until ? '&to=' . $until : '') . ($curSel ? '&currency=' . $curSel : '');

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
        $stmt = client_statement($pdo, $clientId, $from, $until, $curSel);
        billing_stream_pdf(pdf_statement($stmt, billing_company()), pdf_filename('Statement', $client['name'] . ' ' . $stmt['to']));
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
        // Statement and payment request take a CLIENT id (BILLING.md Part 2).
        $opts = ['to' => $to, 'cc' => billing_parse_emails($g('cc')), 'message' => mb_substr($g('message'), 0, 2000), 'client_id' => $clientId];
        if ($action === 'send_statement') {
            $opts['from'] = $from;
            $opts['until'] = $until;
            $opts['currency'] = $curSel;
            $r = billing_send($pdo, 'statement', $clientId, $opts, $adminId);
        } else {
            $r = billing_send($pdo, 'payment_request', $clientId, $opts, $adminId);
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

$stmt = client_statement($pdo, $clientId, $from, $until, $curSel);
$sum = client_summary($pdo, $clientId);
$cur = $stmt['currency'];
$curTotals = $sum['totals'][$cur] ?? ['owed' => 0.0, 'overdue' => 0.0];

view_head('Statement - ' . $client['name']);
echo '<p class="muted"><a href="invoices.php?tab=owing">Billing</a> &rsaquo; <a href="client.php?id=' . $clientId . '">' . e($client['name']) . '</a></p>';
echo view_page_head('Statement: ' . e($client['name']) . ' <span class="tag">' . e($cur) . '</span>');
view_flash();

echo '<section class="card"><div class="bill-stats">'
    . '<p><span class="muted">Period</span><strong>' . e(billing_date($stmt['from'])) . ' to ' . e(billing_date($stmt['to'])) . '</strong></p>'
    . '<p><span class="muted">Opening balance</span><strong>' . e(billing_money($stmt['opening'], $cur)) . '</strong></p>'
    . '<p><span class="muted">Amount owed</span><strong>' . e(billing_money($stmt['closing'], $cur)) . '</strong></p>'
    . '<p><span class="muted">Overdue now</span><strong' . ((float)$curTotals['overdue'] > BILLING_TOLERANCE ? ' class="bill-overdue"' : '') . '>' . e(billing_money((float)$curTotals['overdue'], $cur)) . '</strong></p>'
    . '</div>'
    . (count($stmt['currencies']) > 1 ? '<p class="flash flash-info">This client has invoices in ' . e(implode(', ', $stmt['currencies'])) . '. Each currency has its own statement: '
        . implode(' ', array_map(static fn($c) => '<a href="statement.php?client=' . $clientId . '&amp;currency=' . e($c) . '">' . e($c) . '</a>', $stmt['currencies'])) . '. A payment request is per currency: send the invoices instead.</p>' : '')
    . '<form method="get" action="statement.php" class="bill-filter"><input type="hidden" name="client" value="' . $clientId . '">'
    . ($curSel ? '<input type="hidden" name="currency" value="' . e($curSel) . '">' : '')
    . '<label>From<input type="date" name="from" value="' . e((string)$from) . '"></label>'
    . '<label>To<input type="date" name="to" value="' . e((string)$until) . '"></label>'
    . '<button type="submit" class="secondary">Show</button></form>'
    . '<p class="muted">Leave the dates empty for everything up to today. Invoices that were sent or paid count; drafts and void invoices do not. '
    . 'An invoice dated after the end of the period is left out together with the payments against it. '
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
            . '<td data-label="Due" class="nw">' . ($d['due_date'] ? e(billing_date($d['due_date'])) : view_empty()) . ($d['is_overdue'] ? ' <span class="pill pill-suspended">Overdue</span>' : '') . '</td>'
            . '<td data-label="Total" class="nw num">' . e(billing_money((float)$d['total'], $d['currency'])) . '</td>'
            . '<td data-label="Balance" class="nw num">' . e(billing_money($d['balance'], $d['currency'])) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

[$toList, $ccList] = client_recipients($client);
$owedCurrencies = array_filter($sum['totals'], static fn($t) => (float)$t['owed'] > BILLING_TOLERANCE);
echo '<section class="card"><h2>Email</h2>'
    . '<p class="muted">Send the statement (PDF attached), or a payment request listing what is owed and how to pay.</p>'
    . '<form method="post" action="' . e($self) . '">' . csrf_field()
    . '<label>To<small class="hint">Separate several with commas</small><input type="text" name="to_emails" required maxlength="500" value="' . e(implode(', ', $toList)) . '"></label>'
    . '<label>Copy to<input type="text" name="cc" maxlength="500" value="' . e(implode(', ', $ccList)) . '"></label>'
    . '<label>Personal note<small class="hint">Shown at the top of the email</small><textarea name="message" rows="3" maxlength="2000"></textarea></label>'
    . '<button type="submit" name="action" value="send_statement">Send statement</button>'
    . ((float)$curTotals['owed'] > BILLING_TOLERANCE && count($owedCurrencies) <= 1 ? ' <button type="submit" name="action" value="send_request" class="secondary">Send payment request</button>' : '')
    . '</form></section>';
view_foot();
