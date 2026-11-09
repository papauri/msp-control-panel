<?php
// One client (owner 2026-11-09, BILLING.md Part 2): contact card, what they owe per currency, their invoices and
// quotations, payments (with receipts), recurring schedules and email history. ?id= opens one; ?new=1 adds one.
// A hosted business's client keeps its name and billing contact in step with the business (client_save).
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);
$adminId = (int)$admin['id'];

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$isNewForm = $id === 0;
$errors = [];
$form = null;

function client_back(int $id, string $anchor = ''): void
{
    header('Location: ' . ($id > 0 ? 'client.php?id=' . $id . $anchor : 'clients.php'));
    exit;
}

// A payment belongs to this client (its own, or an older one of its business).
function client_owns_payment(array $client, ?array $pay): bool
{
    if (!$pay) {
        return false;
    }
    if ($pay['client_id'] !== null) {
        return (int)$pay['client_id'] === (int)$client['id'];
    }
    return $client['tenant_id'] !== null && (int)$pay['tenant_id'] === (int)$client['tenant_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $s = static fn(string $k): string => isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : '';
    $action = $s('action');
    $postId = ctype_digit($s('id')) ? (int)$s('id') : 0;
    $client = $postId > 0 ? client_find($pdo, $postId) : null;
    if ($postId > 0 && !$client) {
        flash_set('error', 'That client no longer exists.');
        client_back(0);
    }
    try {
        if ($action === 'save') {
            $in = $_POST;
            $in['id'] = $postId;
            $in['is_active'] = $s('is_active') === '1' ? '1' : '0';
            unset($in['tenant_id']);
            $in['source'] = $client ? (string)$client['source'] : 'manual';
            [$errors] = client_validate($in, billing_setting('default_currency'));
            if (!$errors) {
                $newId = client_save($pdo, $in, $adminId);
                flash_set('ok', $postId > 0 ? 'Saved.' . ($client['tenant_id'] !== null ? ' The business\'s billing contact was updated too.' : '') : 'Client added.');
                client_back($newId);
            }
            $form = $_POST;
        } elseif ($client && $action === 'payment') {
            if (!billing_once_consume('client_pay_' . $postId, $s('once'))) {
                flash_set('info', 'This payment was already recorded (the form was sent twice). See Payments below.');
                client_back($postId, '#payments');
            }
            $docId = ctype_digit($s('document_id')) ? (int)$s('document_id') : 0;
            $d = $docId > 0 ? billing_doc($pdo, $docId) : null;
            if (!$d || !$d['client'] || (int)$d['client']['id'] !== $postId) {
                flash_set('error', 'Choose one of this client\'s invoices.');
                client_back($postId, '#pay');
            }
            $amount = trim($s('amount'));
            if (!preg_match('/^\d{1,11}(\.\d{1,2})?$/', $amount)) {
                flash_set('error', 'Enter the amount received as a number, for example 100000 or 100000.50.');
                client_back($postId, '#pay');
            }
            $r = billing_record_payment($pdo, $docId, (float)$amount, $s('reference'), $s('method'), $adminId);
            if (!$r['ok']) {
                flash_set('error', $r['error']);
                client_back($postId, '#pay');
            }
            $after = billing_doc($pdo, $docId);
            $pay = billing_payment($pdo, (int)$r['payment_id']);
            flash_set('ok', 'Payment recorded against invoice ' . $after['number'] . '.'
                . ($r['paid_in_full'] ? ' It is now paid.' : ' Balance due: ' . billing_money($after['balance'], $after['currency']) . '.')
                . ($pay && (int)$pay['months'] > 0 ? ' Licence extended: paid until is now ' . $pay['paid_until_after'] . '.' : ''));
            if ($pay && (int)$pay['months'] > 0 && !empty($pay['tenant_id'])) {
                header('Location: client.php?id=' . $postId . '#payments');
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                tenant_nudge($pdo, (int)$pay['tenant_id']);
                exit;
            }
            client_back($postId, '#payments');
        } elseif ($client && $action === 'subscribe') {
            // Part 3: "Subscribe" to a plan = a recurring schedule (+ optional extra lines).
            if (!billing_once_consume('client_sub_' . $postId, $s('once'))) {
                flash_set('info', 'This subscription was already added (the form was sent twice). See Recurring invoices below.');
                client_back($postId, '#recurring');
            }
            $extra = [];
            $xi = isset($_POST['extra_item']) && is_array($_POST['extra_item']) ? $_POST['extra_item'] : [];
            $xq = isset($_POST['extra_qty']) && is_array($_POST['extra_qty']) ? $_POST['extra_qty'] : [];
            $xp = isset($_POST['extra_price']) && is_array($_POST['extra_price']) ? $_POST['extra_price'] : [];
            foreach (array_slice(array_keys($xi), 0, 5) as $k) {
                $extra[] = ['item' => is_string($xi[$k]) ? $xi[$k] : '', 'qty' => isset($xq[$k]) && is_string($xq[$k]) && trim($xq[$k]) !== '' ? $xq[$k] : '1',
                    'unit_price' => isset($xp[$k]) && is_string($xp[$k]) ? $xp[$k] : '', 'description' => ''];
            }
            $r = plan_subscribe($pdo, $postId, ['plan_id' => ctype_digit($s('plan_id')) ? (int)$s('plan_id') : 0, 'yearly' => $s('yearly') === '1',
                'next_date' => $s('next_date'), 'send_mode' => $s('send_mode'), 'extra' => $extra], $adminId);
            flash_set($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Subscription added: the first invoice is made on its start date by the daily job.' : $r['error']);
            client_back($postId, '#recurring');
        } elseif ($client && ($action === 'receipt_pdf' || $action === 'receipt_send')) {
            $pay = ctype_digit($s('payment_id')) ? billing_payment($pdo, (int)$s('payment_id')) : null;
            if (!client_owns_payment($client, $pay)) {
                flash_set('error', 'That payment was not found for this client.');
                client_back($postId, '#payments');
            }
            if ($action === 'receipt_pdf') {
                // The receipt as it stood right after this payment: billing_receipt_parts (billing_mail.php); fallback billing_receipt_doc.
                $parts = billing_has_mail() && function_exists('billing_receipt_parts') ? billing_receipt_parts($pdo, (int)$pay['id']) : null;
                if (is_array($parts) && isset($parts['error'])) {
                    flash_set('error', (string)$parts['error']);
                    client_back($postId, '#payments');
                }
                $rdoc = is_array($parts) ? $parts['doc'] : billing_receipt_doc($pdo, $pay);
                $rpay = is_array($parts) ? $parts['payment'] : $pay;
                if (!billing_has_pdf() || !$rdoc) {
                    flash_set('error', 'PDF download is not available yet.');
                    client_back($postId, '#payments');
                }
                billing_stream_pdf(pdf_receipt($rpay, $rdoc, billing_company()), pdf_filename('Receipt', (string)($rpay['receipt_no'] ?? billing_receipt_number($pay, $rdoc))));
            }
            if (!billing_has_mail()) {
                flash_set('error', 'Sending email is not available yet.');
                client_back($postId, '#payments');
            }
            $to = billing_parse_emails($s('to'));
            $bad = array_merge(billing_parse_emails($s('to'), true), billing_parse_emails($s('cc'), true));
            if (!$to || $bad) {
                flash_set('error', $bad ? 'Not a valid email address: ' . implode(', ', $bad) : 'Enter at least one email address to send the receipt to.');
                client_back($postId, '#payments');
            }
            $r = billing_send($pdo, 'receipt', (int)$pay['id'], ['to' => $to, 'cc' => billing_parse_emails($s('cc')), 'message' => ''], $adminId);
            flash_set(!empty($r['ok']) ? 'ok' : 'error', !empty($r['ok']) ? 'Receipt sent to ' . implode(', ', (array)($r['to'] ?? $to)) . '.'
                : 'Not sent: ' . (string)($r['error'] ?? 'unknown error'));
            client_back($postId, '#payments');
        } else {
            http_response_code(400);
            echo 'Invalid request.';
            exit;
        }
    } catch (InvalidArgumentException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        billing_events_clear();
        if ($action !== 'save') {
            flash_set('error', $ex->getMessage());
            client_back($postId);
        }
        $errors[] = $ex->getMessage();
        $form = $_POST;
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        billing_events_clear();
        error_log('client.php: ' . $ex->getMessage());
        if ($action !== 'save') {
            flash_set('error', 'Could not do that. Nothing was changed.');
            client_back($postId);
        }
        $errors[] = 'Could not save. Nothing was changed.';
        $form = $_POST;
    }
    $id = $postId;
    $isNewForm = $postId === 0;
}

$client = $id > 0 ? client_find($pdo, $id) : null;
if ($id > 0 && !$client) {
    http_response_code(404);
    view_head('Not found');
    echo '<p>Client not found. <a href="clients.php">Back to clients</a></p>';
    view_foot();
    exit;
}
$v = static function (string $k, string $default = '') use ($form, $client): string {
    if (is_array($form) && array_key_exists($k, $form) && is_string($form[$k])) {
        return $form[$k];
    }
    if ($client && array_key_exists($k, $client) && $client[$k] !== null) {
        return (string)$client[$k];
    }
    return $default;
};

$detailsForm = static function () use ($client, $v): string {
    $active = is_array($GLOBALS['form'] ?? null) ? (($GLOBALS['form']['is_active'] ?? '') === '1') : (!$client || (int)$client['is_active'] === 1);
    $h = '<form method="post" action="client.php' . ($client ? '?id=' . (int)$client['id'] : '?new=1') . '">' . csrf_field()
        . '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . ($client ? (int)$client['id'] : 0) . '">'
        . '<div class="bill-grid"><label>Kind<select name="kind">';
    foreach (client_kinds() as $k => $l) {
        $h .= '<option value="' . e($k) . '"' . ($v('kind', 'company') === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    $h .= '</select></label>'
        . '<label>Name (company, or the person\'s full name)<input type="text" name="name" required maxlength="190" value="' . e($v('name')) . '"></label>'
        . '<label>Contact person<input type="text" name="contact_name" maxlength="120" value="' . e($v('contact_name')) . '"></label>'
        . '<label>Email (invoices and receipts go here)<input type="email" name="email" maxlength="190" value="' . e($v('email')) . '"></label>'
        . '<label>Copy to (other emails, separated by commas)<input type="text" name="cc_emails" maxlength="500" value="' . e($v('cc_emails')) . '"></label>'
        . '<label>Phone<input type="text" name="phone" maxlength="60" value="' . e($v('phone')) . '"></label>'
        . '<label>Address (printed on invoices)<textarea name="address" rows="3" maxlength="1000">' . e($v('address')) . '</textarea></label>'
        . '<label>Tax number<input type="text" name="tax_number" maxlength="60" value="' . e($v('tax_number')) . '"></label>'
        . '<label>Currency (default on new invoices)<input type="text" name="currency" required maxlength="3" value="' . e($v('currency', billing_setting('default_currency'))) . '"></label>'
        . '</div><label>Notes (only you see these)<textarea name="notes" rows="3" maxlength="2000">' . e($v('notes')) . '</textarea></label>'
        . '<label class="check"><input type="checkbox" name="is_active" value="1"' . ($active ? ' checked' : '') . '> Active (shown in the client list on new invoices)</label>'
        . ($client && $client['tenant_id'] !== null ? '<p class="muted">This client is a hosted business: its name and billing contact are also saved on the business.</p>' : '')
        . '<button type="submit">' . ($client ? 'Save details' : 'Add client') . '</button></form>';
    return $h;
};

if ($isNewForm) {
    view_head('Add client');
    echo '<p class="muted"><a href="clients.php">Clients</a></p><h1>Add client</h1>';
    view_flash();
    foreach ($errors as $m) {
        echo '<p class="flash flash-error">' . e($m) . '</p>';
    }
    echo '<section class="card"><p class="muted">A company or a person you sell to, hosting with us or not. Hosted businesses get their client automatically.</p>'
        . $detailsForm() . '</section>';
    view_foot();
    exit;
}

$cid = (int)$client['id'];
$tenant = $client['tenant_id'] !== null ? tenant_find($pdo, (int)$client['tenant_id']) : null;
$sum = client_summary($pdo, $cid);
$docs = client_documents($pdo, $cid);
$payments = client_payments($pdo, $cid);
$schedules = recurring_list($pdo, $cid);
[$toList, $ccList] = client_recipients($client);
$defTo = implode(', ', $toList);
$defCc = implode(', ', $ccList);
$methods = billing_payment_methods();
$pillClass = ['draft' => 'grace', 'sent' => 'read_only', 'paid' => 'active', 'void' => 'suspended', 'accepted' => 'active',
    'declined' => 'suspended', 'expired' => 'suspended', 'converted' => 'active'];

view_head($client['name']);
echo '<script src="assets/billing.js" defer></script>';
echo '<p class="muted"><a href="clients.php">Clients</a></p>';
$today = status_today();
$trialWords = $tenant ? trial_words($tenant, $today) : '';
echo '<h1>' . e($client['name']) . ' <span class="tag">' . e(client_kinds()[$client['kind']] ?? $client['kind']) . '</span>'
    . ($tenant ? ' <span class="tag">hosted</span>' : '') . ((int)$client['is_active'] !== 1 ? ' <span class="pill pill-suspended">Inactive</span>' : '')
    . ($trialWords !== '' ? ' ' . view_trial_tag($trialWords) : '') . '</h1>';
view_flash();
if ($tenant && trial_ending_within($tenant, $today, 7)) {
    echo '<p class="flash flash-info">The free trial of ' . e($tenant['name']) . ' ends on ' . e(billing_date((string)$tenant['trial_until']))
        . '. <a href="tenant.php?id=' . (int)$tenant['id'] . '#trial">Extend it or convert it to a paid plan</a>.</p>';
}
foreach ($errors as $m) {
    echo '<p class="flash flash-error">' . e($m) . '</p>';
}

echo '<p class="bill-actions"><a class="button" href="document.php?new=invoice&amp;client=' . $cid . '">New invoice</a>'
    . '<a class="button secondary" href="document.php?new=quote&amp;client=' . $cid . '">New quote</a>'
    . '<a class="button secondary" href="statement.php?client=' . $cid . '">Statement</a>'
    . '<a class="button secondary" href="#pay">Record payment</a>'
    . '<a class="button secondary" href="recurring.php?new=1&amp;client=' . $cid . '">New recurring invoice</a></p>';

// Contact card
echo '<section class="card bill-contact"><h2>Contact</h2><div class="bill-stats">'
    . '<p><span class="muted">Contact person</span><strong>' . ($client['contact_name'] ? e($client['contact_name']) : view_empty()) . '</strong></p>'
    . '<p><span class="muted">Email</span><strong>' . ($client['email'] ? e($client['email']) : view_empty()) . '</strong></p>'
    . '<p><span class="muted">Copy to</span><strong>' . ($client['cc_emails'] ? e($client['cc_emails']) : view_empty()) . '</strong></p>'
    . '<p><span class="muted">Phone</span><strong>' . ($client['phone'] ? e($client['phone']) : view_empty()) . '</strong></p>'
    . '<p><span class="muted">Tax number</span><strong>' . ($client['tax_number'] ? e($client['tax_number']) : view_empty()) . '</strong></p>'
    . '<p><span class="muted">Currency</span><strong>' . e($client['currency']) . '</strong></p>'
    . '<p><span class="muted">Hosted business</span><strong>' . ($tenant ? '<a href="tenant.php?id=' . (int)$tenant['id'] . '">' . e($tenant['name']) . '</a>' : 'No') . '</strong></p>'
    . '</div>' . ($client['address'] ? '<p class="pre">' . e($client['address']) . '</p>' : '')
    . ($client['notes'] ? '<p class="muted pre">' . e($client['notes']) . '</p>' : '')
    . (!$client['email'] ? '<p class="muted">No email yet: add one so invoices, receipts and reminders can be emailed.</p>' : '')
    . '<details' . ($form !== null ? ' open' : '') . '><summary>Edit details</summary>' . $detailsForm() . '</details></section>';

// Summary per currency (never added together)
echo '<section class="card"><h2>Account</h2>';
if (!$sum['totals']) {
    echo '<p class="muted">Nothing invoiced yet.</p>';
} else {
    echo '<div class="scroll"><table><thead><tr>' . view_th('Currency', 'Each currency is kept separately; amounts are never added across currencies.', 'Kept apart')
        . view_th('Invoiced', 'Sent and paid invoices.') . view_th('Received', 'Payments recorded.') . view_th('Owed', 'Still to pay on sent invoices.')
        . view_th('Overdue', 'Part of that past its due date.') . '</tr></thead><tbody>';
    foreach ($sum['totals'] as $c => $t) {
        echo '<tr><td data-label="Currency">' . e($c) . '</td>'
            . '<td data-label="Invoiced" class="num nw">' . e(billing_money($t['invoiced'], $c)) . '</td>'
            . '<td data-label="Received" class="num nw">' . e(billing_money($t['received'], $c)) . '</td>'
            . '<td data-label="Owed" class="num nw">' . e(billing_money($t['owed'], $c)) . '</td>'
            . '<td data-label="Overdue" class="num nw' . ($t['overdue'] > BILLING_TOLERANCE ? ' bill-overdue' : '') . '">' . e(billing_money($t['overdue'], $c)) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
if ($sum['last_payment']) {
    $lp = $sum['last_payment'];
    echo '<p class="muted">Last payment: ' . e(billing_money((float)$lp['amount'], (string)$lp['currency'])) . ' on ' . e(billing_date(substr((string)$lp['created_at'], 0, 10))) . '.</p>';
}
echo '</section>';

// Record payment against one of the client's invoices
$payable = array_values(array_filter($docs, static fn($d) => $d['kind'] === 'invoice' && in_array($d['status'], ['draft', 'sent'], true) && $d['balance'] > BILLING_TOLERANCE));
echo '<section class="card" id="pay"><h2>Record payment</h2>';
if (!$payable) {
    echo '<p class="muted">No unpaid invoices. Create an invoice first; every payment is recorded against an invoice'
        . ($tenant ? ' (licence payments without an invoice are on the <a href="tenant.php?id=' . (int)$tenant['id'] . '">business page</a>)' : '') . '.</p>';
} else {
    echo '<form method="post" action="client.php?id=' . $cid . '" class="js-once">' . csrf_field()
        . '<input type="hidden" name="action" value="payment"><input type="hidden" name="id" value="' . $cid . '">'
        . '<input type="hidden" name="once" value="' . e(billing_once_issue('client_pay_' . $cid)) . '">'
        . '<label>Invoice<select name="document_id" required>';
    foreach ($payable as $d) {
        echo '<option value="' . (int)$d['id'] . '">' . e($d['number'] . ' · balance ' . billing_money($d['balance'], $d['currency'])
            . ($d['due_date'] ? ' · due ' . billing_date($d['due_date']) : '') . ($d['status'] === 'draft' ? ' · draft' : '')) . '</option>';
    }
    echo '</select></label><label>Amount received<input type="text" name="amount" inputmode="decimal" required value="'
        . e(number_format($payable[0]['balance'], 2, '.', '')) . '"><small class="muted">In the invoice\'s currency; at most its balance.</small></label>'
        . '<label>Paid by<select name="method" required>';
    foreach ($methods as $mk => $ml) {
        echo '<option value="' . e($mk) . '">' . e($ml) . '</option>';
    }
    echo '</select></label><label>Reference (receipt, transfer or cheque number)<input type="text" name="reference" required minlength="3" maxlength="190"></label>'
        . '<button type="submit">Record payment</button></form>';
}
echo '</section>';

// Documents
echo '<section class="card"><h2>Invoices and quotations</h2>';
if (!$docs) {
    echo '<p class="muted">None yet.</p>';
} else {
    echo '<div class="scroll"><table class="list"><thead><tr>' . view_th('No.', 'Invoice or quotation number.') . view_th('Kind', 'Invoice or quotation.')
        . view_th('Date', 'Date on the document.') . view_th('Due', 'Due date, or valid until for a quotation.')
        . view_th('Total', 'Amount of the document.') . view_th('Balance', 'Still to pay (sent invoices).') . view_th('Status', 'Where it stands.') . '</tr></thead><tbody>';
    foreach ($docs as $d) {
        $st = billing_statuses($d['kind']);
        echo '<tr><td data-label="No." class="nw"><a href="document.php?id=' . (int)$d['id'] . '">' . e($d['number']) . '</a></td>'
            . '<td data-label="Kind">' . e(billing_kind_label($d['kind'])) . '</td>'
            . '<td data-label="Date" class="nw">' . e(billing_date($d['issue_date'])) . '</td>'
            . '<td data-label="Due" class="nw">' . ($d['due_date'] ? e(billing_date($d['due_date'])) : view_empty()) . '</td>'
            . '<td data-label="Total" class="nw num">' . e(billing_money((float)$d['total'], $d['currency'])) . '</td>'
            . '<td data-label="Balance" class="nw num">' . ($d['kind'] === 'invoice' && $d['status'] === 'sent' ? e(billing_money($d['balance'], $d['currency'])) : view_empty()) . '</td>'
            . '<td data-label="Status" class="nw"><span class="pill pill-' . e($pillClass[$d['status']] ?? 'grace') . '">' . e($st[$d['status']] ?? $d['status']) . '</span>'
            . ($d['is_overdue'] ? ' <span class="pill pill-suspended">Overdue</span>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

// Payments, each with its receipt
echo '<section class="card" id="payments"><h2>Payments</h2>';
if (!$payments) {
    echo '<p class="muted">None recorded yet.</p>';
} else {
    $hidden = csrf_field() . '<input type="hidden" name="id" value="' . $cid . '">';
    echo '<div class="scroll"><table><thead><tr>' . view_th('Recorded', 'When the payment was entered.') . view_th('Amount', 'Money received.')
        . view_th('For', 'The invoice it paid, or a licence payment made on the business page.') . view_th('Paid by', 'How it was paid.')
        . view_th('Reference', 'Receipt, transfer or cheque number.') . view_th('Receipt', 'Download the receipt, or email it.') . '</tr></thead><tbody>';
    foreach ($payments as $p) {
        echo '<tr><td data-label="Recorded" class="nw">' . e($p['created_at']) . '</td>'
            . '<td data-label="Amount" class="nw num">' . e(billing_money((float)$p['amount'], (string)$p['currency'])) . '</td>'
            . '<td data-label="For">' . ($p['document_id'] !== null ? '<a href="document.php?id=' . (int)$p['document_id'] . '">Invoice ' . e((string)$p['doc_number']) . '</a>'
                : 'Licence' . ((int)$p['months'] > 0 ? ', ' . (int)$p['months'] . ' month' . ((int)$p['months'] === 1 ? '' : 's') : '')) . '</td>'
            . '<td data-label="Paid by">' . ($p['method'] ? e($methods[(string)$p['method']] ?? (string)$p['method']) : view_empty()) . '</td>'
            . '<td data-label="Reference">' . (($p['reference'] ?? '') !== '' ? e($p['reference']) : view_empty()) . '</td>'
            . '<td data-label="Receipt"><form method="post" action="client.php?id=' . $cid . '" class="inline">' . $hidden
            . '<input type="hidden" name="action" value="receipt_pdf"><input type="hidden" name="payment_id" value="' . (int)$p['id'] . '">'
            . '<button type="submit" class="secondary">Download receipt</button></form> '
            . '<details><summary>Send receipt</summary><form method="post" action="client.php?id=' . $cid . '" class="spaced">' . $hidden
            . '<input type="hidden" name="action" value="receipt_send"><input type="hidden" name="payment_id" value="' . (int)$p['id'] . '">'
            . '<label>To<input type="text" name="to" required maxlength="500" value="' . e($defTo) . '"></label>'
            . '<label>Copy to<input type="text" name="cc" maxlength="500" value="' . e($defCc) . '"></label>'
            . '<button type="submit">Send receipt</button></form></details></td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

// Recurring schedules
echo '<section class="card" id="recurring"><h2>Recurring invoices</h2>';
if (!$schedules) {
    echo '<p class="muted">None. <a href="recurring.php?new=1&amp;client=' . $cid . '">Add a recurring invoice</a> for anything billed every month, quarter or year.</p>';
} else {
    $modes = recurring_send_modes();
    echo '<div class="scroll"><table><thead><tr>' . view_th('Title', 'What is billed.') . view_th('Every', 'How often.')
        . view_th('Next invoice', 'Date of the next invoice.') . view_th('Amount', 'Total of the lines, before tax.')
        . view_th('Then', 'What happens to the invoice.') . view_th('Status', 'Active or paused.') . '</tr></thead><tbody>';
    foreach ($schedules as $r) {
        $tot = billing_calc_totals(recurring_items($r), 0, 0);
        echo '<tr><td data-label="Title"><a href="recurring.php?id=' . (int)$r['id'] . '">' . e($r['title']) . '</a></td>'
            . '<td data-label="Every" class="nw">' . (int)$r['interval_months'] . ' month' . ((int)$r['interval_months'] === 1 ? '' : 's') . '</td>'
            . '<td data-label="Next invoice" class="nw">' . e(billing_date($r['next_date'])) . '</td>'
            . '<td data-label="Amount" class="nw num">' . e(billing_money($tot['subtotal'], $r['currency'])) . '</td>'
            . '<td data-label="Then">' . e($modes[$r['send_mode']] ?? $r['send_mode']) . '</td>'
            . '<td data-label="Status">' . ((int)$r['is_active'] === 1 ? '<span class="pill pill-active">Active</span>' : '<span class="pill pill-suspended">Paused</span>') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

// Subscribe to a plan (Part 3): a recurring schedule from a saved item that is a plan.
billing_seed_plans($pdo, $adminId); // first use only (locked, audited, once): the usual plans at price 0
$plans = plan_list($pdo);
$pct = plan_discount_pct(billing_setting('annual_discount_pct'));
echo '<section class="card" id="subscribe"><h2>Subscribe to a plan</h2>';
if (!$plans) {
    echo '<p class="muted">No plans yet. Mark a saved item as a plan in <a href="settings.php#items">Settings, Saved items</a>.</p>';
} else {
    echo '<p class="muted">Bills this client every month, quarter or year from a plan (support, hosting, maintenance, a licence...). '
        . 'The daily job makes each invoice on its date. A licence plan for a hosted business also extends its licence when paid.</p>'
        . '<form method="post" action="client.php?id=' . $cid . '#recurring" class="js-once">' . csrf_field()
        . '<input type="hidden" name="action" value="subscribe"><input type="hidden" name="id" value="' . $cid . '">'
        . '<input type="hidden" name="once" value="' . e(billing_once_issue('client_sub_' . $cid)) . '">'
        . '<div class="bill-grid"><label>Plan<select name="plan_id" required>';
    foreach ($plans as $pl) {
        echo '<option value="' . (int)$pl['id'] . '">' . e(plan_label($pl)) . '</option>';
    }
    echo '</select></label><label>First invoice on<input type="date" name="next_date" required value="' . e($today) . '"></label>'
        . '<label>Then<select name="send_mode">';
    foreach (recurring_send_modes() as $mk => $ml) {
        echo '<option value="' . e($mk) . '"' . ($mk === 'approve' ? ' selected' : '') . '>' . e($ml) . '</option>';
    }
    echo '</select></label></div>'
        . '<label class="check"><input type="checkbox" name="yearly" value="1"> Bill a monthly plan once a year (12 months'
        . ($pct > 0 ? ', with a ' . e(rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.')) . '% annual discount line' : '') . ')</label>'
        . '<fieldset class="modules"><legend>Extra lines on every invoice (optional)</legend>';
    for ($i = 0; $i < 2; $i++) {
        echo '<div class="bill-grid"><label>Item<input type="text" name="extra_item[]" maxlength="190"></label>'
            . '<label>Qty<input type="text" name="extra_qty[]" inputmode="decimal" value="1"></label>'
            . '<label>Unit price<input type="text" name="extra_price[]" inputmode="decimal"></label></div>';
    }
    echo '</fieldset><button type="submit">Subscribe</button></form>';
}
echo '</section>';

// Email history
$log = billing_email_log($pdo, null, null, 50, $cid);
echo '<section class="card"><h2>Email history</h2>';
if (!$log) {
    echo '<p class="muted">Nothing emailed yet.</p>';
} else {
    echo '<div class="scroll"><table><thead><tr>' . view_th('When', 'Time it was sent.') . view_th('What', 'Kind of email.')
        . view_th('To', 'Who it went to.') . view_th('Subject', 'Email subject.') . view_th('Result', 'Sent, failed, or test (went to the test address only).') . '</tr></thead><tbody>';
    foreach ($log as $l) {
        $pill = $l['status'] === 'sent' ? 'active' : ($l['status'] === 'test' ? 'grace' : 'suspended');
        echo '<tr><td data-label="When" class="nw">' . e($l['created_at']) . '</td><td data-label="What">'
            . ($l['document_id'] !== null ? '<a href="document.php?id=' . (int)$l['document_id'] . '">' . e($l['kind']) . '</a>' : e($l['kind'])) . '</td>'
            . '<td data-label="To">' . view_trunc($l['to_email']) . '</td><td data-label="Subject">' . view_trunc($l['subject']) . '</td>'
            . '<td data-label="Result"><span class="pill pill-' . e($pill) . '">' . e($l['status']) . '</span>'
            . (($l['error'] ?? '') !== '' ? ' <span class="muted">' . e($l['error']) . '</span>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';
view_foot();
