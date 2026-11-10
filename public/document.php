<?php
// One invoice or quotation (owner 2026-11-09): edit the client and lines, then download, email, take payments,
// void, convert. ?id= opens one; ?new=invoice|quote&client= (or &tenant= for a hosted business) starts a new one
// (its number is given on first save). Rules live in app/billing.php; this page only collects input and shows results.
// Contract: BILLING.md (Part 2: documents belong to a client; cost prices give the owner the profit).
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();
$pdo = db();
$admin = auth_require($pdo);
$adminId = (int)$admin['id'];
$today = status_today();

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$newKind = isset($_GET['new']) && in_array($_GET['new'], ['invoice', 'quote'], true) ? (string)$_GET['new'] : '';
$preClient = isset($_GET['client']) && ctype_digit((string)$_GET['client']) ? (int)$_GET['client'] : 0;
if ($preClient === 0 && isset($_GET['tenant']) && ctype_digit((string)$_GET['tenant'])) {
    $tc = client_for_tenant($pdo, (int)$_GET['tenant']); // old links: a business means its client
    $preClient = $tc ? (int)$tc['id'] : 0;
}
$errors = [];
$form = null;      // raw posted header values after a failed save
$formLines = null; // raw posted lines after a failed save

// Redirect to a document; after a licence extension also nudge that business to check in now (sent after the redirect).
function doc_back(int $docId, int $nudgeTenant = 0): void
{
    header('Location: ' . ($docId > 0 ? 'document.php?id=' . $docId : 'invoices.php'));
    if ($nudgeTenant > 0) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        tenant_nudge(db(), $nudgeTenant);
    }
    exit;
}

function doc_posted_lines(): array
{
    $cols = ['qty' => 'line_qty', 'item' => 'line_item', 'description' => 'line_description', 'unit_price' => 'line_unit_price',
        'cost_price' => 'line_cost_price', 'catalog_item_id' => 'line_catalog_id'];
    $data = [];
    $n = 0;
    foreach ($cols as $k => $name) {
        $data[$k] = isset($_POST[$name]) && is_array($_POST[$name]) ? array_values($_POST[$name]) : [];
        $n = max($n, count($data[$k]));
    }
    $lines = [];
    for ($i = 0; $i < min($n, 200); $i++) {
        $l = [];
        foreach ($cols as $k => $name) {
            $v = $data[$k][$i] ?? '';
            $l[$k] = is_string($v) ? $v : '';
        }
        $lines[] = $l;
    }
    return $lines;
}

// The new-client fields posted with the document form (client picker = "new").
function doc_posted_new_client(): array
{
    $g = static fn(string $k): string => isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : '';
    return ['kind' => $g('nc_kind'), 'name' => $g('nc_name'), 'contact_name' => $g('nc_contact'), 'email' => $g('nc_email'),
        'cc_emails' => $g('nc_cc'), 'phone' => $g('nc_phone'), 'address' => $g('nc_address'), 'currency' => $g('currency'),
        'source' => 'document', 'is_active' => '1'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $s = static fn(string $k): string => isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : '';
    $action = $s('action');
    $postId = ctype_digit($s('id')) ? (int)$s('id') : 0;
    $cur = $postId > 0 ? billing_doc($pdo, $postId) : null;
    if ($postId > 0 && !$cur) {
        flash_set('error', 'That document no longer exists.');
        doc_back(0);
    }
    $kindLabel = $cur ? strtolower(billing_kind_label($cur['kind'])) : '';

    try {
        if ($action === 'save') {
            $in = $_POST;
            $lines = doc_posted_lines();
            $newClient = null;
            if ($cur) {
                $mode = billing_editable($cur);
                if ($mode === 'none') {
                    throw new InvalidArgumentException('This ' . $kindLabel . ' is ' . $cur['status'] . ' and can no longer be changed.');
                }
                $in['kind'] = $cur['kind'];
                if ($mode === 'lines') {
                    foreach (['client_id', 'tenant_id', 'customer_name', 'customer_contact', 'customer_email', 'customer_address', 'issue_date', 'due_date',
                        'currency', 'salesperson', 'notes', 'discount', 'tax_rate', 'extend_months'] as $k) {
                        $in[$k] = $cur[$k] === null ? '' : (string)$cur[$k];
                    }
                }
            }
            $cErr = [];
            if (($in['client_id'] ?? '') === 'new' && (!$cur || billing_editable($cur) === 'all')) {
                [$cErr, $newClient] = client_validate(doc_posted_new_client(), billing_setting('default_currency'));
                $in['client_id'] = '';
                $in['tenant_id'] = '';
                $map = ['customer_name' => 'name', 'customer_contact' => 'contact_name', 'customer_email' => 'email', 'customer_address' => 'address'];
                foreach ($map as $dk => $ck) {
                    if (trim((string)($in[$dk] ?? '')) === '') {
                        $in[$dk] = $newClient[$ck];
                    }
                }
            } elseif (ctype_digit((string)($in['client_id'] ?? '')) && (int)$in['client_id'] > 0) {
                $in['tenant_id'] = '';
                $pc = client_find($pdo, (int)$in['client_id']);
                if ($pc && trim((string)($in['customer_name'] ?? '')) === '') {
                    // No script: print the client's details.
                    $in['customer_name'] = $pc['name'];
                    $in['customer_contact'] = (string)$pc['contact_name'];
                    $in['customer_email'] = (string)$pc['email'];
                    $in['customer_address'] = (string)$pc['address'];
                }
            }
            [$errors, $doc, $items] = billing_doc_validate($in, $lines);
            $errors = array_merge($cErr, $errors);
            if (!$cur && $newClient === null && empty($doc['client_id'])) {
                $errors[] = 'Choose the client, or "New client" to add one.';
            }
            if (!$errors) {
                $doc['id'] = $postId;
                $pdo->beginTransaction();
                if ($newClient !== null) {
                    $doc['client_id'] = client_save($pdo, $newClient, $adminId);
                }
                $newId = billing_doc_save($pdo, $doc, $items, $adminId);
                $pdo->commit();
                billing_events_flush($pdo);
                flash_set('ok', ($postId > 0 ? 'Saved.' : billing_kind_label($doc['kind']) . ' created. Download or send it below.')
                    . ($newClient !== null ? ' New client added: ' . $newClient['name'] . '.' : ''));
                doc_back($newId);
            }
            $form = $_POST;
            $formLines = $lines;
            $newKind = $cur ? '' : $doc['kind'];
            $preClient = (int)($doc['client_id'] ?? 0);
        } elseif ($cur && $action === 'pdf') {
            if (!billing_has_pdf()) {
                flash_set('error', 'PDF download is not available yet.');
                doc_back($postId);
            }
            $bytes = pdf_document($cur, billing_company());
            billing_stream_pdf($bytes, pdf_filename(billing_kind_label($cur['kind']), (string)$cur['number']));
        } elseif ($cur && ($action === 'send' || $action === 'reminder')) {
            if (!billing_has_mail()) {
                flash_set('error', 'Sending email is not available yet.');
                doc_back($postId);
            }
            $to = billing_parse_emails($s('to'));
            $cc = billing_parse_emails($s('cc'));
            $bad = array_merge(billing_parse_emails($s('to'), true), billing_parse_emails($s('cc'), true));
            if (!$to || $bad) {
                flash_set('error', $bad ? 'Not a valid email address: ' . implode(', ', $bad) : 'Enter at least one email address to send to.');
                doc_back($postId);
            }
            $kind = $action === 'reminder' ? 'reminder' : $cur['kind'];
            $r = billing_send($pdo, $kind, $postId, ['to' => $to, 'cc' => $cc, 'message' => mb_substr(trim($s('message')), 0, 2000)], $adminId);
            if (!empty($r['ok']) && $cur['kind'] === 'invoice') {
                billing_settle_zero($pdo, $postId, $adminId); // a zero-total invoice that is now sent is paid
            }
            flash_set(!empty($r['ok']) ? 'ok' : 'error', !empty($r['ok'])
                ? ($action === 'reminder' ? 'Reminder sent to ' : ucfirst($kindLabel) . ' sent to ') . implode(', ', (array)($r['to'] ?? $to)) . '.'
                : 'Not sent: ' . (string)($r['error'] ?? 'unknown error'));
            doc_back($postId);
        } elseif ($cur && ($action === 'payment_void' || $action === 'payment_refund')) {
            $pay = ctype_digit($s('payment_id')) ? billing_payment($pdo, (int)$s('payment_id')) : null;
            if (!$pay || (int)$pay['document_id'] !== $postId) {
                flash_set('error', 'That payment was not found on this invoice.');
                doc_back($postId);
            }
            if (!billing_once_consume('pay_fix_' . (int)$pay['id'], $s('once'))) {
                flash_set('info', 'That was already done (the form was sent twice). See Payments below.');
                doc_back($postId);
            }
            if ($action === 'payment_void') {
                $r = billing_void_payment($pdo, (int)$pay['id'], $s('reason'), $adminId);
                $done = 'Payment voided. ';
            } else {
                $amount = trim($s('amount'));
                if (!preg_match('/^\d{1,11}(\.\d{1,2})?$/', $amount)) {
                    flash_set('error', 'Enter the amount given back as a number, for example 50000.');
                    doc_back($postId);
                }
                $r = billing_refund_payment($pdo, (int)$pay['id'], (float)$amount, $s('method'), $s('reference'), false, $s('void_invoice') === '1', $adminId);
                $done = 'Refund recorded. ';
            }
            flash_set($r['ok'] ? 'ok' : 'error', $r['ok'] ? $done . $r['words'] : $r['error']);
            doc_back($postId, $r['ok'] && !empty($pay['tenant_id']) ? (int)$pay['tenant_id'] : 0);
        } elseif ($cur && $action === 'payment') {
            if (!billing_once_consume('doc_pay_' . $postId, $s('once'))) {
                flash_set('info', 'This payment was already recorded (the form was sent twice). See Payments below.');
                doc_back($postId);
            }
            $amount = trim($s('amount'));
            if (!preg_match('/^\d{1,11}(\.\d{1,2})?$/', $amount)) {
                flash_set('error', 'Enter the amount received as a number, for example 100000 or 100000.50.');
                doc_back($postId);
            }
            $r = billing_record_payment($pdo, $postId, (float)$amount, $s('reference'), $s('method'), $adminId);
            if (!$r['ok']) {
                flash_set('error', $r['error']);
                doc_back($postId);
            }
            $after = billing_doc($pdo, $postId);
            $pay = billing_payment($pdo, (int)$r['payment_id']);
            $extended = $pay && (int)$pay['months'] > 0;
            flash_set('ok', 'Payment recorded.' . ($r['paid_in_full'] ? ' The invoice is now paid.' : ' Balance due: ' . billing_money($after['balance'], $after['currency']) . '.')
                . ($extended ? ' Licence extended: paid until is now ' . $pay['paid_until_after'] . '.' : ''));
            doc_back($postId, $extended ? (int)$pay['tenant_id'] : 0);
        } elseif ($cur && ($action === 'receipt_pdf' || $action === 'receipt_send')) {
            $pay = ctype_digit($s('payment_id')) ? billing_payment($pdo, (int)$s('payment_id')) : null;
            if (!$pay || (int)$pay['document_id'] !== $postId) {
                flash_set('error', 'That payment was not found on this invoice.');
                doc_back($postId);
            }
            if (!billing_payment_live($pay) || billing_payment_is_refund($pay)) {
                flash_set('error', !billing_payment_live($pay) ? 'This payment is void, so it has no receipt.' : 'This is a refund, so it has no receipt.');
                doc_back($postId);
            }
            if ($action === 'receipt_pdf') {
                if (!billing_has_pdf()) {
                    flash_set('error', 'PDF download is not available yet.');
                    doc_back($postId);
                }
                // The receipt as it stood right after this payment (balance then): billing_receipt_parts (billing_mail.php).
                $parts = billing_has_mail() && function_exists('billing_receipt_parts') ? billing_receipt_parts($pdo, (int)$pay['id']) : null;
                if (is_array($parts) && isset($parts['error'])) {
                    flash_set('error', (string)$parts['error']);
                    doc_back($postId);
                }
                [$rp, $rd] = is_array($parts) ? [$parts['payment'], $parts['doc']] : [$pay, $cur];
                billing_stream_pdf(pdf_receipt($rp, $rd, billing_company()), pdf_filename('Receipt', (string)($rp['receipt_no'] ?? billing_receipt_number($pay, $cur))));
            }
            if (!billing_has_mail()) {
                flash_set('error', 'Sending email is not available yet.');
                doc_back($postId);
            }
            $to = billing_parse_emails($s('to'));
            $bad = array_merge(billing_parse_emails($s('to'), true), billing_parse_emails($s('cc'), true));
            if (!$to || $bad) {
                flash_set('error', $bad ? 'Not a valid email address: ' . implode(', ', $bad) : 'Enter at least one email address to send the receipt to.');
                doc_back($postId);
            }
            $r = billing_send($pdo, 'receipt', (int)$pay['id'], ['to' => $to, 'cc' => billing_parse_emails($s('cc')), 'message' => ''], $adminId);
            flash_set(!empty($r['ok']) ? 'ok' : 'error', !empty($r['ok']) ? 'Receipt sent to ' . implode(', ', (array)($r['to'] ?? $to)) . '.' : 'Not sent: ' . (string)($r['error'] ?? 'unknown error'));
            doc_back($postId);
        } elseif ($cur && $action === 'void') {
            $err = billing_void($pdo, $postId, $adminId);
            flash_set($err === '' ? 'ok' : 'error', $err === '' ? 'Invoice voided. It no longer counts as owed.' : $err);
            doc_back($postId);
        } elseif ($cur && $action === 'convert') {
            $newId = billing_quote_to_invoice($pdo, $postId, $adminId);
            flash_set('ok', 'Invoice created from the quotation. Check it, then send it.');
            doc_back($newId);
        } elseif ($cur && $action === 'status') {
            $want = $s('status');
            $err = billing_set_status($pdo, $postId, $want, $adminId);
            $now = $err === '' ? billing_doc_row($pdo, $postId) : null;
            flash_set($err === '' ? 'ok' : 'error', $err === '' ? 'Marked ' . $want . '.'
                . ($now && $now['status'] === 'paid' ? ' The total is zero, so it counts as paid in full.' : '') : $err);
            doc_back($postId);
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
            doc_back($postId);
        }
        $errors[] = $ex->getMessage();
        $form = $form ?? $_POST;
        $formLines = $formLines ?? doc_posted_lines();
        $newKind = $cur ? '' : (($_POST['kind'] ?? '') === 'quote' ? 'quote' : 'invoice');
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        billing_events_clear();
        error_log('document.php: ' . $ex->getMessage());
        if ($action !== 'save') {
            flash_set('error', 'Could not do that. Nothing was changed.');
            doc_back($postId);
        }
        $errors[] = 'Could not save. Nothing was changed.';
        $form = $form ?? $_POST;
        $formLines = $formLines ?? doc_posted_lines();
        $newKind = $cur ? '' : (($_POST['kind'] ?? '') === 'quote' ? 'quote' : 'invoice');
    }
    $id = $postId;
}

// The document to show: an existing one, or a blank new one.
$doc = $id > 0 ? billing_doc($pdo, $id) : null;
if ($id > 0 && !$doc) {
    http_response_code(404);
    view_head('Not found');
    echo '<p>Document not found. <a href="invoices.php">Back to billing</a></p>';
    view_foot();
    exit;
}
if (!$doc) {
    if ($newKind === '') {
        header('Location: invoices.php');
        exit;
    }
    $c = $preClient > 0 ? client_find($pdo, $preClient) : null;
    $t = $c && $c['tenant_id'] !== null ? tenant_find($pdo, (int)$c['tenant_id']) : null;
    $days = (int)billing_setting($newKind === 'quote' ? 'quote_valid_days' : 'invoice_due_days');
    $doc = [
        'id' => 0, 'kind' => $newKind, 'number' => '', 'status' => 'draft', 'client_id' => $c ? (int)$c['id'] : null, 'tenant_id' => $t ? (int)$t['id'] : null,
        'customer_name' => $c ? $c['name'] : '', 'customer_contact' => $c ? (string)$c['contact_name'] : '',
        'customer_email' => $c ? (string)$c['email'] : '', 'customer_address' => $c ? (string)$c['address'] : '',
        'issue_date' => $today, 'due_date' => status_add_days($today, $days),
        'currency' => $c ? $c['currency'] : billing_setting('default_currency'), 'salesperson' => billing_setting('salesperson'),
        'notes' => '', 'subtotal' => 0, 'discount' => 0, 'tax_rate' => billing_setting('tax_rate'), 'tax' => 0, 'total' => 0,
        'amount_paid' => 0, 'extend_months' => 0, 'source_id' => null, 'items' => [], 'balance' => 0.0, 'is_overdue' => false,
        'tenant' => $t, 'client' => $c, 'sent_at' => null,
    ];
}
$isNew = (int)$doc['id'] === 0;
$isInvoice = $doc['kind'] === 'invoice';
$label = billing_kind_label($doc['kind']);
$mode = $isNew ? 'all' : billing_editable($doc);
$cur = $doc['currency'];
$client = $doc['client'] ?? null;
$fv = static function (string $k) use ($form, $doc): string {
    if (is_array($form) && array_key_exists($k, $form) && is_string($form[$k])) {
        return $form[$k];
    }
    return ($doc[$k] ?? null) === null ? '' : (string)$doc[$k];
};
$num = static fn($x): string => rtrim(rtrim(number_format((float)$x, 2, '.', ''), '0'), '.');
$statuses = billing_statuses($doc['kind']);
$pillClass = ['draft' => 'grace', 'sent' => 'read_only', 'paid' => 'active', 'void' => 'suspended', 'accepted' => 'active',
    'declined' => 'suspended', 'expired' => 'suspended', 'converted' => 'active'];

billing_seed_catalog($pdo, $adminId); // first use only: fills the saved items (locked, audited, once)
$catalog = billing_catalog($pdo);
$clients = client_options($pdo, (int)($doc['client_id'] ?? 0));
$tenantRows = [];
foreach ($pdo->query('SELECT id, name, plan, business_type, modules, domain, monthly_fee, currency FROM cp_tenants') as $tr) {
    $tenantRows[(int)$tr['id']] = $tr;
}

view_head($isNew ? 'New ' . strtolower($label) : $label . ' ' . $doc['number']);
echo '<script src="assets/billing.js" defer></script>';
view_flash();
foreach ($errors as $m) {
    echo '<p class="flash flash-error">' . e($m) . '</p>';
}
echo '<p class="muted"><a href="invoices.php' . ($isInvoice ? '' : '?tab=quotes') . '">Billing</a>'
    . ($client ? ' &rsaquo; <a href="client.php?id=' . (int)$client['id'] . '">' . e($client['name']) . '</a>' : '') . '</p>';
echo '<h1>' . ($isNew ? 'New ' . e(strtolower($label)) : e($label . ' ' . $doc['number']) . ' <span class="pill pill-' . e($pillClass[$doc['status']] ?? 'grace') . '">'
    . e($statuses[$doc['status']] ?? $doc['status']) . '</span>' . ($doc['is_overdue'] ? ' <span class="pill pill-suspended">Overdue</span>' : '')) . '</h1>';

// Owner-only profit block (never printed on the PDF or in emails).
function doc_profit_html(array $items, float $discount, string $cur): string
{
    $p = billing_profit($items, $discount);
    if (!$p['complete'] && !$p['partial']) {
        return '<p class="muted bill-profit">Profit: add cost prices to the lines to see it (only you see this; it is never printed).</p>';
    }
    return '<p class="bill-profit"><span class="muted">Cost</span> <strong>' . e(billing_money($p['cost'], $cur)) . '</strong> '
        . '<span class="muted">Profit</span> <strong' . ($p['profit'] < -BILLING_TOLERANCE ? ' class="bill-overdue"' : '') . '>' . e(billing_money($p['profit'], $cur)) . '</strong>'
        . ($p['margin'] !== null ? ' <span class="muted">(' . e(number_format($p['margin'], 1)) . '%)</span>' : '')
        . ($p['partial'] ? ' <span class="muted">on the ' . e('lines with a cost price; ' . $p['unknown_lines'] . ' line' . ($p['unknown_lines'] === 1 ? ' has' : 's have') . ' none') . '</span>' : '')
        . ' <span class="muted">Only you see this; it is never printed.</span></p>';
}

if (!$isNew) {
    // Summary and the actions that do not need a form of their own.
    echo '<section class="card"><div class="bill-stats">'
        . '<p><span class="muted">Client</span><strong>' . ($client ? '<a href="client.php?id=' . (int)$client['id'] . '">' . e($client['name']) . '</a>' : e($doc['customer_name'])) . '</strong></p>'
        . '<p><span class="muted">Date</span><strong>' . e(billing_date($doc['issue_date'])) . '</strong></p>'
        . '<p><span class="muted">' . ($isInvoice ? 'Due' : 'Valid until') . '</span><strong>' . ($doc['due_date'] ? e(billing_date($doc['due_date'])) : view_empty()) . '</strong></p>'
        . '<p><span class="muted">Total</span><strong>' . e(billing_money((float)$doc['total'], $cur)) . '</strong></p>';
    if ($isInvoice) {
        echo '<p><span class="muted">Paid</span><strong>' . e(billing_money((float)$doc['amount_paid'], $cur)) . '</strong></p>'
            . '<p><span class="muted">Balance due</span><strong' . ($doc['is_overdue'] ? ' class="bill-overdue"' : '') . '>' . e(billing_money($doc['balance'], $cur)) . '</strong></p>';
    }
    echo '</div>' . doc_profit_html($doc['items'], (float)$doc['discount'], $cur);
    if (!empty($doc['source_id'])) {
        $src = billing_doc_row($pdo, (int)$doc['source_id']);
        if ($src) {
            echo '<p class="muted">Made from quotation <a href="document.php?id=' . (int)$src['id'] . '">' . e($src['number']) . '</a>.</p>';
        }
    }
    if (!$isInvoice && $doc['status'] === 'converted') {
        $st = $pdo->prepare("SELECT id, number FROM cp_documents WHERE kind = 'invoice' AND source_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([(int)$doc['id']]);
        if ($inv = $st->fetch()) {
            echo '<p class="muted">Turned into invoice <a href="document.php?id=' . (int)$inv['id'] . '">' . e($inv['number']) . '</a>.</p>';
        }
    }
    if ($isInvoice && (int)$doc['extend_months'] > 0 && $doc['tenant']) {
        echo '<p class="muted">When paid in full, ' . e($doc['tenant']['name']) . '\'s licence is extended by ' . (int)$doc['extend_months']
            . ' month' . ((int)$doc['extend_months'] === 1 ? '' : 's') . '.</p>';
    }
    if (!empty($doc['sent_at'])) {
        echo '<p class="muted">First sent ' . e($doc['sent_at']) . ((int)$doc['reminder_count'] > 0 ? '; ' . (int)$doc['reminder_count'] . ' reminder'
            . ((int)$doc['reminder_count'] === 1 ? '' : 's') . ', last ' . e((string)$doc['last_reminder_at']) : '') . '.</p>';
    }
    $hidden = csrf_field() . '<input type="hidden" name="id" value="' . (int)$doc['id'] . '">';
    echo '<div class="bill-actions">'
        . '<form method="post" action="document.php?id=' . (int)$doc['id'] . '" class="inline">' . $hidden
        . '<input type="hidden" name="action" value="pdf"><button type="submit">Download PDF</button></form>';
    if ($doc['status'] === 'draft') {
        echo '<form method="post" action="document.php?id=' . (int)$doc['id'] . '" class="inline" data-confirm="Mark as sent without emailing it (for example, handed over by hand)?">' . $hidden
            . '<input type="hidden" name="action" value="status"><input type="hidden" name="status" value="sent"><button type="submit" class="secondary">Mark as sent</button></form>';
    }
    if (!$isInvoice && $doc['status'] !== 'converted') {
        echo '<form method="post" action="document.php?id=' . (int)$doc['id'] . '" class="inline" data-confirm="Create an invoice from this quotation?">' . $hidden
            . '<input type="hidden" name="action" value="convert"><button type="submit">Convert to invoice</button></form>';
        foreach (['accepted' => 'Mark accepted', 'declined' => 'Mark declined'] as $stt => $btn) {
            if ($doc['status'] !== $stt) {
                echo '<form method="post" action="document.php?id=' . (int)$doc['id'] . '" class="inline">' . $hidden
                    . '<input type="hidden" name="action" value="status"><input type="hidden" name="status" value="' . e($stt) . '"><button type="submit" class="secondary">' . e($btn) . '</button></form>';
            }
        }
    }
    if ($isInvoice && in_array($doc['status'], ['draft', 'sent'], true) && (float)$doc['amount_paid'] <= BILLING_TOLERANCE) {
        echo '<form method="post" action="document.php?id=' . (int)$doc['id'] . '" class="inline" data-confirm="Void this invoice? It stays on record but no longer counts as owed. This cannot be undone.">' . $hidden
            . '<input type="hidden" name="action" value="void"><button type="submit" class="danger">Void invoice</button></form>';
    }
    if ($client) {
        echo '<a class="button secondary" href="statement.php?client=' . (int)$client['id'] . '">Statement</a>';
    }
    echo '</div></section>';
}

// Editor (or a read-only view when nothing may change).
$lockHead = $mode === 'lines';
$dis = $lockHead ? ' disabled' : '';
$lines = $formLines;
if ($lines === null) {
    $lines = [];
    foreach ($doc['items'] as $it) {
        $lines[] = ['qty' => $num($it['qty']), 'item' => (string)$it['item'], 'description' => (string)$it['description'],
            'unit_price' => number_format((float)$it['unit_price'], 2, '.', ''),
            'cost_price' => $it['cost_price'] !== null ? number_format((float)$it['cost_price'], 2, '.', '') : '',
            'catalog_item_id' => $it['catalog_item_id'] !== null ? (string)(int)$it['catalog_item_id'] : ''];
    }
}
if (!$lines && $mode !== 'none') {
    $lines[] = ['qty' => '1', 'item' => '', 'description' => '', 'unit_price' => '', 'cost_price' => '', 'catalog_item_id' => ''];
}
$lineRow = static function (array $l, bool $edit) use ($cur): string {
    $l += ['cost_price' => '', 'catalog_item_id' => ''];
    $total = (preg_match('/^\d+(\.\d+)?$/', (string)$l['qty']) && preg_match('/^-?\d+(\.\d+)?$/', (string)$l['unit_price']))
        ? billing_money(billing_line_total((float)$l['qty'], (float)$l['unit_price']), $cur) : '';
    if (!$edit) {
        return '<tr><td data-label="Qty" class="num">' . e($l['qty']) . '</td><td data-label="Item">' . e($l['item']) . '</td>'
            . '<td data-label="Description" class="pre">' . e($l['description']) . '</td>'
            . '<td data-label="Unit price" class="num nw">' . e(billing_money((float)$l['unit_price'], $cur)) . '</td>'
            . '<td data-label="Cost" class="num nw muted">' . ($l['cost_price'] !== '' ? e(billing_money((float)$l['cost_price'], $cur)) : view_empty()) . '</td>'
            . '<td data-label="Total" class="num nw">' . e($total) . '</td></tr>';
    }
    return '<tr class="line"><td data-label="Qty"><input type="text" name="line_qty[]" inputmode="decimal" class="in-qty" required value="' . e($l['qty']) . '"></td>'
        . '<td data-label="Item"><input type="text" name="line_item[]" maxlength="190" class="in-item" value="' . e($l['item']) . '">'
        . '<input type="hidden" name="line_catalog_id[]" class="in-cat" value="' . e($l['catalog_item_id']) . '"></td>'
        . '<td data-label="Description"><textarea name="line_description[]" rows="2" maxlength="2000" class="in-desc">' . e($l['description']) . '</textarea></td>'
        . '<td data-label="Unit price"><input type="text" name="line_unit_price[]" inputmode="decimal" class="in-price" value="' . e($l['unit_price']) . '"></td>'
        . '<td data-label="Cost (yours)"><input type="text" name="line_cost_price[]" inputmode="decimal" class="in-cost" title="What one costs you. Only you see it; never printed." value="' . e($l['cost_price']) . '"></td>'
        . '<td data-label="Total" class="num nw line-total">' . e($total) . '</td>'
        . '<td data-label=""><button type="button" class="secondary js-remove-line" title="Remove this line">Remove</button></td></tr>';
};

if ($mode === 'none') {
    echo '<section class="card"><h2>Lines</h2><div class="scroll"><table class="bill-lines"><thead><tr>'
        . view_th('Qty', 'Quantity.') . view_th('Item', 'What was sold.') . view_th('Description', 'Details printed under the item.')
        . view_th('Unit price', 'Price of one.') . view_th('Cost', 'What one cost you (never printed).') . view_th('Total', 'Quantity times unit price.') . '</tr></thead><tbody>';
    foreach ($lines as $l) {
        echo $lineRow($l, false);
    }
    echo '</tbody></table></div>' . doc_totals_html($doc) . '<p class="muted">This ' . e(strtolower($label)) . ' is '
        . e($statuses[$doc['status']] ?? $doc['status']) . ' and can no longer be changed.</p></section>';
} else {
    echo '<form method="post" action="document.php' . ($isNew ? '?new=' . e($doc['kind']) . ($preClient ? '&amp;client=' . $preClient : '') : '?id=' . (int)$doc['id'])
        . '" class="card bill-editor js-once" id="doc-form" data-currency="' . e($cur) . '">'
        . csrf_field() . '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . (int)$doc['id'] . '">'
        . '<input type="hidden" name="kind" value="' . e($doc['kind']) . '">';
    if ($lockHead) {
        echo '<p class="flash flash-info">This invoice has been sent, so only its lines can change. Re-send it after saving if the customer should see the change.</p>';
    }
    $selC = $fv('client_id');
    if ($selC === '' && $client && !is_array($form)) {
        $selC = (string)$client['id']; // older document: its business's client
    }
    echo '<h2>Client</h2><div class="bill-grid">'
        . '<label>Client<select name="client_id" id="client-pick"' . $dis . '><option value="">Choose…</option>'
        . '<option value="new"' . ($selC === 'new' ? ' selected' : '') . '>+ New client…</option>';
    foreach ($clients as $c) {
        $t = $c['tenant_id'] !== null ? ($tenantRows[(int)$c['tenant_id']] ?? null) : null;
        $sub = $t ? billing_subscription_lines($t, 1)[0] : null;
        echo '<option value="' . (int)$c['id'] . '"' . ((string)$c['id'] === $selC ? ' selected' : '')
            . ' data-name="' . e($c['name']) . '" data-contact="' . e((string)$c['contact_name']) . '" data-email="' . e((string)$c['email'])
            . '" data-address="' . e((string)$c['address']) . '" data-currency="' . e($c['currency']) . '" data-hosted="' . ($t ? '1' : '0')
            . '" data-domain="' . e((string)($t['domain'] ?? '')) . '"'
            . ($sub ? ' data-sub-item="' . e($sub['item']) . '" data-sub-desc="' . e($sub['description']) . '" data-sub-price="' . e(number_format($sub['unit_price'], 2, '.', '')) . '"' : '')
            . '>' . e($c['name']) . ($c['kind'] === 'individual' ? ' (individual)' : '') . ($t ? ' · hosted' : '') . ((int)$c['is_active'] !== 1 ? ' (inactive)' : '') . '</option>';
    }
    $nc = static fn(string $k): string => is_array($form) && isset($form[$k]) && is_string($form[$k]) ? $form[$k] : '';
    echo '</select></label></div>'
        . '<fieldset class="modules bill-newclient" id="new-client"' . ($selC === 'new' ? '' : ' hidden') . '><legend>New client</legend>'
        . '<label>Kind<select name="nc_kind">';
    foreach (client_kinds() as $k => $l) {
        echo '<option value="' . e($k) . '"' . ($nc('nc_kind') === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    echo '</select></label>'
        . '<label>Name (company, or the person\'s full name)<input type="text" name="nc_name" id="nc_name" maxlength="190" value="' . e($nc('nc_name')) . '"></label>'
        . '<label>Contact person<input type="text" name="nc_contact" maxlength="120" value="' . e($nc('nc_contact')) . '"></label>'
        . '<label>Email<input type="email" name="nc_email" maxlength="190" value="' . e($nc('nc_email')) . '"></label>'
        . '<label>Copy to (other emails)<input type="text" name="nc_cc" maxlength="500" value="' . e($nc('nc_cc')) . '"></label>'
        . '<label>Phone<input type="text" name="nc_phone" maxlength="60" value="' . e($nc('nc_phone')) . '"></label>'
        . '<label>Address<textarea name="nc_address" rows="3" maxlength="1000">' . e($nc('nc_address')) . '</textarea></label>'
        . '<p class="muted">Saved as a client when this ' . e(strtolower($label)) . ' is saved. More details can be added on the client page.</p></fieldset>'
        . '<h2>Printed customer details</h2><div class="bill-grid">'
        . '<label>Customer name (printed)<input type="text" name="customer_name" id="customer_name" maxlength="190" value="' . e($fv('customer_name')) . '"' . $dis . '></label>'
        . '<label>Contact person<input type="text" name="customer_contact" id="customer_contact" maxlength="120" value="' . e($fv('customer_contact')) . '"' . $dis . '></label>'
        . '<label>Customer email (printed)<input type="email" name="customer_email" id="customer_email" maxlength="190" value="' . e($fv('customer_email')) . '"' . $dis . '></label>'
        . '<label>Address (printed)<textarea name="customer_address" id="customer_address" rows="3" maxlength="1000"' . $dis . '>' . e($fv('customer_address')) . '</textarea></label>'
        . '</div><p class="muted">Filled from the client; change them only for what is printed on this ' . e(strtolower($label)) . '.</p>'
        . '<h2>Details</h2><div class="bill-grid">'
        . '<label>Date<input type="date" name="issue_date" required value="' . e($fv('issue_date')) . '"' . $dis . '></label>'
        . '<label>' . ($isInvoice ? 'Due date' : 'Valid until') . '<input type="date" name="due_date" value="' . e($fv('due_date')) . '"' . $dis . '></label>'
        . '<label>Currency<input type="text" name="currency" id="currency" required maxlength="3" value="' . e($fv('currency')) . '"' . $dis . '></label>'
        . '<label>Salesperson<input type="text" name="salesperson" maxlength="120" value="' . e($fv('salesperson')) . '"' . $dis . '></label>'
        . '</div>';

    echo '<h2>Lines</h2><div class="scroll"><table class="bill-lines"><thead><tr>'
        . view_th('Qty', 'Quantity, for example months, sites or units.') . view_th('Item', 'Short name printed in the Item column.')
        . view_th('Description', 'Details printed beside the item.') . view_th('Unit price', 'Price of one, before tax.')
        . view_th('Cost', 'What one costs you; gives the profit. Never printed.', 'Yours, never printed')
        . view_th('Total', 'Quantity times unit price.') . view_th('', 'Remove a line.', 'Remove')
        . '</tr></thead><tbody id="lines">';
    foreach ($lines as $l) {
        echo $lineRow($l, true);
    }
    echo '</tbody></table></div>'
        . '<template id="line-template">' . $lineRow(['qty' => '1', 'item' => '', 'description' => '', 'unit_price' => ''], true) . '</template>'
        . '<div class="bill-add"><button type="button" class="secondary" id="add-line">Add line</button>';
    if ($catalog) {
        $cats = billing_catalog_categories();
        echo '<label class="inline-label">Saved item<select id="catalog-pick"><option value="">Choose…</option>';
        foreach (billing_catalog_grouped($catalog) as $cat => $group) {
            echo '<optgroup label="' . e($cats[$cat] ?? $cat) . '">';
            foreach ($group as $c) {
                echo '<option value="' . (int)$c['id'] . '" data-item="' . e($c['item']) . '" data-desc="' . e((string)$c['description'])
                    . '" data-price="' . e(number_format((float)$c['unit_price'], 2, '.', '')) . '" data-cost="'
                    . e($c['cost_price'] !== null ? number_format((float)$c['cost_price'], 2, '.', '') : '') . '">' . e($c['item'])
                    . (($c['sku'] ?? '') !== '' ? ' [' . e($c['sku']) . ']' : '')
                    . ' (' . e(billing_money((float)$c['unit_price'], $c['currency'])) . (($c['unit'] ?? '') !== '' ? ' / ' . e($c['unit']) : '') . ')</option>';
            }
            echo '</optgroup>';
        }
        echo '</select></label><button type="button" class="secondary" id="add-catalog">Add saved item</button>';
    }
    echo '<label class="inline-label">Months<input type="number" id="sub-months" min="1" max="60" value="1"></label>'
        . '<button type="button" class="secondary" id="add-sub" title="Adds the chosen hosted business\'s licence at its monthly fee">Add licence subscription</button>'
        . '<span class="muted" id="sub-msg" hidden>Choose a hosted business as the client first.</span></div>';

    echo '<div class="bill-grid">'
        . '<label>Discount (amount)<input type="text" name="discount" id="discount" inputmode="decimal" value="' . e($fv('discount') === '' ? '0' : $num($fv('discount'))) . '"' . $dis . '></label>'
        . '<label>' . e(billing_setting('tax_label')) . ' rate (%)<input type="text" name="tax_rate" id="tax_rate" inputmode="decimal" value="' . e($num($fv('tax_rate'))) . '"' . $dis . '></label>'
        . '<label>When paid in full, extend the licence by (months)<input type="number" name="extend_months" id="extend_months" min="0" max="60" value="' . e($fv('extend_months') === '' ? '0' : $fv('extend_months')) . '"' . $dis . '>'
        . '<small class="muted">' . ($isInvoice ? 'Hosted businesses only. 0 = this invoice does not change the licence.' : 'Carried to the invoice when this quotation is converted.') . '</small></label>'
        . '</div><label>Notes (printed)<textarea name="notes" rows="3" maxlength="2000"' . $dis . '>' . e($fv('notes')) . '</textarea></label>';
    echo '<div class="bill-totals" id="totals" data-tax-label="' . e(billing_setting('tax_label')) . '">'
        . '<p><span>Subtotal</span><strong id="t-sub">' . e(billing_money((float)$doc['subtotal'], $cur)) . '</strong></p>'
        . '<p><span>Discount</span><strong id="t-disc">' . e(billing_money((float)$doc['discount'], $cur)) . '</strong></p>'
        . '<p><span>' . e(billing_setting('tax_label')) . '</span><strong id="t-tax">' . e(billing_money((float)$doc['tax'], $cur)) . '</strong></p>'
        . '<p class="grand"><span>Total</span><strong id="t-total">' . e(billing_money((float)$doc['total'], $cur)) . '</strong></p>'
        . '<p class="muted"><span>Profit (yours)</span><strong id="t-profit">' . view_empty() . '</strong></p></div>'
        . '<button type="submit">' . ($isNew ? 'Create ' . e(strtolower($label)) : 'Save') . '</button></form>';
}

function doc_totals_html(array $doc): string
{
    $cur = (string)$doc['currency'];
    $h = '<div class="bill-totals">';
    if ((float)$doc['discount'] > BILLING_TOLERANCE || (float)$doc['tax'] > BILLING_TOLERANCE) {
        $h .= '<p><span>Subtotal</span><strong>' . e(billing_money((float)$doc['subtotal'], $cur)) . '</strong></p>';
    }
    if ((float)$doc['discount'] > BILLING_TOLERANCE) {
        $h .= '<p><span>Discount</span><strong>' . e(billing_money((float)$doc['discount'], $cur)) . '</strong></p>';
    }
    if ((float)$doc['tax'] > BILLING_TOLERANCE) {
        $h .= '<p><span>' . e(billing_setting('tax_label')) . ' (' . e((float)$doc['tax_rate']) . '%)</span><strong>' . e(billing_money((float)$doc['tax'], $cur)) . '</strong></p>';
    }
    $h .= '<p class="grand"><span>Total</span><strong>' . e(billing_money((float)$doc['total'], $cur)) . '</strong></p>';
    if ($doc['kind'] === 'invoice' && (float)$doc['amount_paid'] > BILLING_TOLERANCE) {
        $h .= '<p><span>Amount paid</span><strong>' . e(billing_money((float)$doc['amount_paid'], $cur)) . '</strong></p>'
            . '<p class="grand"><span>Balance due</span><strong>' . e(billing_money(billing_balance($doc), $cur)) . '</strong></p>';
    }
    return $h . '</div>';
}

if (!$isNew) {
    $hidden = csrf_field() . '<input type="hidden" name="id" value="' . (int)$doc['id'] . '">';
    // Recipients: the client's email and copy-to list; the printed customer email only when the client has none.
    [$toList, $ccList] = client_recipients($client, $doc);
    $defTo = implode(', ', $toList);
    $defCc = implode(', ', $ccList);
    $canSend = !in_array($doc['status'], ['void', 'converted'], true);
    $canRemind = $isInvoice && $doc['status'] === 'sent' && $doc['balance'] > BILLING_TOLERANCE;
    if ($canSend) {
        echo '<section class="card"><h2>Email</h2>'
            . '<p class="muted">Sends a branded email with the ' . e(strtolower($label)) . ' attached as a PDF. Sending a draft marks it as sent.</p>'
            . '<form method="post" action="document.php?id=' . (int)$doc['id'] . '">' . $hidden
            . '<label>To (separate several with commas)<input type="text" name="to" required maxlength="500" value="' . e($defTo) . '"></label>'
            . '<label>Copy to<input type="text" name="cc" maxlength="500" value="' . e($defCc) . '"></label>'
            . '<label>Personal note (shown at the top of the email)<textarea name="message" rows="3" maxlength="2000"></textarea></label>'
            . '<button type="submit" name="action" value="send">Send ' . e(strtolower($label)) . '</button>'
            . ($canRemind ? ' <button type="submit" name="action" value="reminder" class="secondary">Send reminder</button>' : '')
            . '</form></section>';
    }

    if ($isInvoice) {
        if (in_array($doc['status'], ['draft', 'sent'], true) && $doc['balance'] > BILLING_TOLERANCE) {
            echo '<section class="card" id="pay"><h2>Record payment</h2>'
                . '<p class="muted">Money received against this invoice. When the balance reaches zero the invoice is marked paid'
                . ((int)$doc['extend_months'] > 0 && $doc['tenant'] ? ' and the licence is extended by ' . (int)$doc['extend_months'] . ' month' . ((int)$doc['extend_months'] === 1 ? '' : 's') : '') . '.</p>'
                . '<form method="post" action="document.php?id=' . (int)$doc['id'] . '" class="js-once">' . $hidden . '<input type="hidden" name="action" value="payment">'
                . '<input type="hidden" name="once" value="' . e(billing_once_issue('doc_pay_' . (int)$doc['id'])) . '">'
                . '<label>Amount received (' . e($cur) . ')<input type="text" name="amount" inputmode="decimal" required value="' . e(number_format($doc['balance'], 2, '.', '')) . '"></label>'
                . '<label>Paid by<select name="method" required>';
            foreach (billing_payment_methods() as $mk => $ml) {
                echo '<option value="' . e($mk) . '">' . e($ml) . '</option>';
            }
            echo '</select></label><label>Reference (receipt, transfer or cheque number)<input type="text" name="reference" required minlength="3" maxlength="190"></label>'
                . '<button type="submit">Record payment</button></form></section>';
        }
        $payments = billing_doc_payments($pdo, (int)$doc['id']);
        if ($payments) {
            $methods = billing_payment_methods();
            echo '<section class="card"><h2>Payments</h2><div class="scroll"><table><thead><tr>'
                . view_th('Recorded', 'When the payment was entered.') . view_th('Amount', 'Money received (a refund, money given back, is a minus amount).')
                . view_th('Paid by', 'How it was paid.') . view_th('Reference', 'Receipt, transfer or cheque number.')
                . view_th('Receipt', 'Download the receipt, or email it. Void or refund a payment here too.') . '</tr></thead><tbody>';
            foreach ($payments as $p) {
                $state = billing_payment_state($p, $payments);
                $isLive = billing_payment_live($p);
                $live = $isLive && !billing_payment_is_refund($p);
                $fix = '';
                if ($live) {
                    $left = billing_payment_refundable($p, $payments);
                    $fixOnce = e(billing_once_issue('pay_fix_' . (int)$p['id']));
                    $fix = '<details><summary>Void or refund</summary>';
                    if ($left >= round((float)$p['amount'], 2) - BILLING_TOLERANCE) {
                        $fix .= '<form method="post" action="document.php?id=' . (int)$doc['id'] . '" class="spaced js-once" data-confirm="Void this payment? It stops counting everywhere and the invoice balance goes back up.">' . $hidden
                            . '<input type="hidden" name="action" value="payment_void"><input type="hidden" name="payment_id" value="' . (int)$p['id'] . '"><input type="hidden" name="once" value="' . $fixOnce . '">'
                            . '<h3>Void: entered by mistake</h3><p class="muted">The payment stops counting; no money went back. '
                            . ((int)$doc['extend_months'] > 0 ? 'If the invoice is no longer paid in full, the licence time it added is taken back.' : '') . '</p>'
                            . '<label>Why<input type="text" name="reason" required minlength="5" maxlength="255" placeholder="Entered twice"></label>'
                            . '<button type="submit" class="danger">Void payment</button></form>';
                    }
                    $fix .= '<form method="post" action="document.php?id=' . (int)$doc['id'] . '" class="spaced js-once" data-confirm="Record this refund? The invoice balance goes back up by the amount.">' . $hidden
                        . '<input type="hidden" name="action" value="payment_refund"><input type="hidden" name="payment_id" value="' . (int)$p['id'] . '"><input type="hidden" name="once" value="' . $fixOnce . '">'
                        . '<h3>Refund: money given back</h3><p class="muted">Up to ' . e(billing_money($left, (string)$p['currency'])) . '. It counts as money out on the day you record it. '
                        . 'The invoice then shows a balance due again (and reminders follow), unless nothing is left paid and you cancel the sale below.</p>'
                        . '<label>Amount given back<input type="text" name="amount" inputmode="decimal" required value="' . e(number_format($left, 2, '.', '')) . '"></label>'
                        . '<label>Given back by<select name="method" required>';
                    foreach (billing_payment_methods() as $mk => $ml) {
                        $fix .= '<option value="' . e($mk) . '"' . ($mk === (string)$p['method'] ? ' selected' : '') . '>' . e($ml) . '</option>';
                    }
                    $fix .= '</select></label><label>Reference<input type="text" name="reference" required minlength="3" maxlength="190"></label>'
                        . '<label class="check"><input type="checkbox" name="void_invoice" value="1" checked> If nothing is left paid, void the invoice (sale cancelled, no reminders)</label>'
                        . '<button type="submit" class="danger">Record refund</button></form></details>';
                }
                echo '<tr' . (!$isLive ? ' class="muted"' : '') . '><td data-label="Recorded" class="nw">' . e($p['created_at']) . '</td>'
                    . '<td data-label="Amount" class="nw num">' . (!$isLive ? '<s>' : '') . e(billing_money((float)$p['amount'], (string)$p['currency'])) . (!$isLive ? '</s>' : '')
                    . ($state !== '' ? '<br><span class="pill pill-' . (!$isLive ? 'suspended' : 'grace') . '">' . e($state) . '</span>' : '') . '</td>'
                    . '<td data-label="Paid by">' . e($methods[(string)$p['method']] ?? (string)$p['method']) . '</td>'
                    . '<td data-label="Reference">' . e($p['reference']) . '</td>';
                if (!$live) {
                    echo '<td data-label="Receipt">' . view_empty() . '</td></tr>';
                    continue;
                }
                echo '<td data-label="Receipt"><form method="post" action="document.php?id=' . (int)$doc['id'] . '" class="inline">' . $hidden
                    . '<input type="hidden" name="action" value="receipt_pdf"><input type="hidden" name="payment_id" value="' . (int)$p['id'] . '">'
                    . '<button type="submit" class="secondary">Download receipt</button></form> '
                    . '<details><summary>Send receipt</summary><form method="post" action="document.php?id=' . (int)$doc['id'] . '" class="spaced">' . $hidden
                    . '<input type="hidden" name="action" value="receipt_send"><input type="hidden" name="payment_id" value="' . (int)$p['id'] . '">'
                    . '<label>To<input type="text" name="to" required maxlength="500" value="' . e($defTo) . '"></label>'
                    . '<label>Copy to<input type="text" name="cc" maxlength="500" value="' . e($defCc) . '"></label>'
                    . '<button type="submit">Send receipt</button></form></details>' . $fix . '</td></tr>';
            }
            echo '</tbody></table></div></section>';
        }
    }

    $log = billing_email_log($pdo, (int)$doc['id']);
    echo '<section class="card"><h2>Email history</h2>';
    if (!$log) {
        echo '<p class="muted">Nothing emailed yet.</p>';
    } else {
        echo '<div class="scroll"><table><thead><tr>' . view_th('When', 'Time it was sent.') . view_th('What', 'Invoice, quotation, reminder or receipt.')
            . view_th('To', 'Who it went to.') . view_th('Subject', 'Email subject.') . view_th('Result', 'Sent, failed, or test (went to the test address only).')
            . '</tr></thead><tbody>';
        foreach ($log as $l) {
            $pill = $l['status'] === 'sent' ? 'active' : ($l['status'] === 'test' ? 'grace' : 'suspended');
            echo '<tr><td data-label="When" class="nw">' . e($l['created_at']) . '</td><td data-label="What">' . e($l['kind']) . '</td>'
                . '<td data-label="To">' . view_trunc($l['to_email']) . '</td><td data-label="Subject">' . view_trunc($l['subject']) . '</td>'
                . '<td data-label="Result"><span class="pill pill-' . e($pill) . '">' . e($l['status']) . '</span>'
                . (($l['error'] ?? '') !== '' ? ' <span class="muted">' . e($l['error']) . '</span>' : '') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
}
view_foot();
