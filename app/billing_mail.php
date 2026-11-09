<?php
// Sends billing documents by email with the PDF attached (BILLING.md "Email", Part 2 "Agent AUTOMATION").
// Needs app/bootstrap.php loaded first. app/billing.php, app/pdf.php (TCPDF) and PHPMailer are loaded only when used.
//
// billing_send(PDO, kind, id, opts, adminId): ['ok'=>bool, 'error'=>string, 'to'=>actual recipients, 'test'=>bool, 'log_id'=>int]
//   kind invoice | quote | reminder | recurring_invoice | quote_accepted | thank_you_business : id = cp_documents.id
//   kind receipt | thank_you_payment : id = cp_payments.id (any client's payment; a payment that is not against an invoice,
//        such as a licence payment, gets a receipt too)
//   kind statement | payment_request : id = cp_clients.id ($opts['client_id'] wins; legacy $opts['tenant_id'] maps to its client)
//   kind welcome                      : id = cp_clients.id
//   kind trial_started | trial_ending | trial_ended | trial_converted : id = cp_tenants.id (Part 3; to the business's client,
//        else its billing email; $opts['today'] = the day the daily job ran)
//   $opts: to (array|comma string; overrides the default recipients), cc (array|string; when the key is present it replaces the
//   default cc), message (personal note at the top), from / until / currency (statement), title (recurring invoice).
// Recipients: the client's email + cc_emails; for older rows without a client, the business's billing_email + cc_emails;
// then the document's customer_email. Every attempt is logged in cp_email_log (status sent | failed | test, with client_id)
// and audited (no secrets). While MAIL_TEST_TO is set every email goes only there (app/mailer.php), logged as test; the
// document is still marked sent / reminded so the whole flow can be tested before go-live.
// billing_mail_build() prepares an email without sending it (the Outbox preview uses it without building PDFs).

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/email_templates.php';

function billing_mail_tolerance(): float
{
    return defined('BILLING_TOLERANCE') ? (float)BILLING_TOLERANCE : 0.005;
}

/** The kinds billing_send() can send. */
function billing_mail_kinds(): array
{
    return ['invoice', 'quote', 'reminder', 'payment_request', 'statement', 'receipt', 'thank_you_payment', 'thank_you_business',
        'welcome', 'quote_accepted', 'recurring_invoice', 'trial_started', 'trial_ending', 'trial_ended', 'trial_converted'];
}

/** Plain words for a kind ("Thank you for payment"), for the Outbox and Automations pages. */
function billing_mail_kind_label(string $kind): string
{
    $labels = ['invoice' => 'Invoice', 'quote' => 'Quotation', 'reminder' => 'Payment reminder', 'payment_request' => 'Payment request',
        'statement' => 'Statement', 'receipt' => 'Receipt', 'thank_you_payment' => 'Thank you for your payment',
        'thank_you_business' => 'Thank you for your business', 'welcome' => 'Welcome', 'quote_accepted' => 'Quotation accepted',
        'recurring_invoice' => 'Regular invoice', 'test' => 'Test email', 'trial_started' => 'Free trial started',
        'trial_ending' => 'Free trial ending', 'trial_ended' => 'Free trial ended', 'trial_converted' => 'Thank you for choosing us'];
    return $labels[$kind] ?? ucfirst(str_replace('_', ' ', $kind));
}

/** Today minus the due date, as billing_due_reminders(): -3 = due in 3 days, 0 = due today, 7 = 7 days overdue. */
function billing_mail_days_past_due(string $due, string $today): int
{
    $a = DateTime::createFromFormat('!Y-m-d', substr($due, 0, 10));
    $b = DateTime::createFromFormat('!Y-m-d', substr($today, 0, 10));
    if (!$a || !$b) {
        return 0;
    }
    $d = $a->diff($b);
    return $d->invert ? -(int)$d->days : (int)$d->days;
}

/**
 * Default recipients. Pure. The client's email + cc first; a row without a client (older documents) falls back to the
 * business's billing_email + cc_emails, then to the document's customer_email. $opts to / cc override.
 */
function billing_mail_recipients(?array $client, ?array $tenant, ?array $doc, array $opts): array
{
    $to = [];
    $cc = [];
    if ($client) {
        $to = mail_split((string)($client['email'] ?? ''));
        $cc = mail_split((string)($client['cc_emails'] ?? ''));
    }
    if (!$to && $tenant) {
        $to = mail_split((string)($tenant['billing_email'] ?? ''));
        if (!$cc) {
            $cc = mail_split((string)($tenant['cc_emails'] ?? ''));
        }
    }
    if (!$to && $doc) {
        $to = mail_split((string)($doc['customer_email'] ?? ''));
    }
    if (isset($opts['to']) && mail_split($opts['to'])) {
        $to = mail_split($opts['to']);
    }
    if (array_key_exists('cc', $opts)) {
        $cc = mail_split($opts['cc'] ?? '');
    }
    return [$to, array_values(array_udiff($cc, $to, 'strcasecmp'))];
}

/**
 * The function that really sends (mail_send by default). Tests replace it with a fake so nothing can leave:
 * billing_mail_sender(function (array $m) { ... return ['ok'=>true, ...]; }); billing_mail_sender(null, true) restores it.
 */
function billing_mail_sender(?callable $set = null, bool $reset = false): callable
{
    static $fn = null;
    if ($reset) {
        $fn = null;
    }
    if ($set !== null) {
        $fn = $set;
    }
    return $fn ?? 'mail_send';
}

function billing_mail_log(PDO $pdo, ?int $tenantId, ?int $clientId, ?int $docId, string $kind, array $to, string $subject, string $status, string $error, array $attachments, int $adminId): int
{
    $st = $pdo->prepare(
        'INSERT INTO cp_email_log (tenant_id, client_id, document_id, kind, to_email, subject, status, error, attachment, admin_id, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $att = implode(', ', $attachments);
    $st->execute([
        $tenantId ?: null,
        $clientId ?: null,
        $docId ?: null,
        substr($kind, 0, 30),
        substr(implode(', ', $to), 0, 500),
        mb_substr($subject, 0, 255, 'UTF-8'),
        $status,
        $error === '' ? null : mb_substr($error, 0, 500, 'UTF-8'),
        $att === '' ? null : mb_substr($att, 0, 190, 'UTF-8'),
        $adminId > 0 ? $adminId : null,
        date('Y-m-d H:i:s'), // PHP clock (APP_TZ); the database clock differs on the host
    ]);
    return (int)$pdo->lastInsertId();
}

/** A client row (client_find from app/clients.php when loaded, else a plain read of cp_clients). */
function billing_mail_client(PDO $pdo, ?int $clientId): ?array
{
    if (!$clientId) {
        return null;
    }
    if (function_exists('client_find')) {
        return client_find($pdo, $clientId);
    }
    $st = $pdo->prepare('SELECT * FROM cp_clients WHERE id = ?');
    $st->execute([$clientId]);
    $r = $st->fetch();
    return $r ?: null;
}

/** The client linked to a hosted business (read only: never creates one here). */
function billing_mail_client_for_tenant(PDO $pdo, int $tenantId): ?array
{
    $st = $pdo->prepare('SELECT id FROM cp_clients WHERE tenant_id = ?');
    $st->execute([$tenantId]);
    $id = $st->fetchColumn();
    return $id ? billing_mail_client($pdo, (int)$id) : null;
}

/** The client of a document: its own 'client' (billing_doc), its client_id, or its business's client. */
function billing_mail_doc_client(PDO $pdo, array $doc): ?array
{
    if (isset($doc['client']) && is_array($doc['client'])) {
        return $doc['client'];
    }
    if (!empty($doc['client_id'])) {
        $c = billing_mail_client($pdo, (int)$doc['client_id']);
        if ($c) {
            return $c;
        }
    }
    return !empty($doc['tenant_id']) ? billing_mail_client_for_tenant($pdo, (int)$doc['tenant_id']) : null;
}

/** A client in the shape the PDFs read for the customer block (name, contact_name, billing_address, currency). Pure. */
function billing_mail_client_party(array $client): array
{
    return [
        'id' => $client['tenant_id'] ?? null,
        'name' => (string)($client['name'] ?? ''),
        'contact_name' => ($client['kind'] ?? 'company') === 'individual' ? '' : (string)($client['contact_name'] ?? ''),
        'billing_address' => (string)($client['address'] ?? ''),
        'billing_email' => (string)($client['email'] ?? ''),
        'cc_emails' => (string)($client['cc_emails'] ?? ''),
        'currency' => (string)($client['currency'] ?? ''),
    ];
}

/** Customer details on the document filled from the client where the document has none (so PDFs show the client). Pure. */
function billing_mail_fill_customer(array $doc, ?array $client): array
{
    if (!$client) {
        return $doc;
    }
    if (trim((string)($doc['customer_name'] ?? '')) === '' && trim((string)($doc['customer_contact'] ?? '')) === '') {
        $doc['customer_name'] = (string)($client['name'] ?? '');
        if (($client['kind'] ?? 'company') !== 'individual') {
            $doc['customer_contact'] = (string)($client['contact_name'] ?? '');
        }
    }
    if (trim((string)($doc['customer_address'] ?? '')) === '') {
        $doc['customer_address'] = (string)($client['address'] ?? '');
    }
    if (trim((string)($doc['customer_email'] ?? '')) === '') {
        $doc['customer_email'] = (string)($client['email'] ?? '');
    }
    $doc['client'] = $client;
    return $doc;
}

/**
 * The invoice as it stood right after $payment (pure): amount_paid = the payments on it with id <= this payment's id,
 * balance = total - that. Returns [$doc, $payment] with $payment['paid_to_date'] set (pdf_receipt reads it), so a reprint
 * of an earlier part payment never shows today's balance.
 */
function billing_mail_receipt_state(array $doc, array $payments, array $payment): array
{
    $pid = (int)($payment['id'] ?? 0);
    $paid = 0.0;
    foreach ($payments as $p) {
        if ((int)($p['id'] ?? 0) <= $pid) {
            $paid = round($paid + (float)($p['amount'] ?? 0), 2);
        }
    }
    $doc['amount_paid'] = number_format($paid, 2, '.', '');
    $doc['balance'] = round((float)($doc['total'] ?? 0) - $paid, 2);
    if (($doc['kind'] ?? 'invoice') === 'invoice' && ($doc['status'] ?? '') !== 'void') {
        $doc['status'] = $doc['balance'] <= billing_mail_tolerance() ? 'paid' : 'sent';
    }
    $payment['paid_to_date'] = $paid;
    return [$doc, $payment];
}

/**
 * Everything a receipt needs for one payment, for the email and for a download:
 *   $r = billing_receipt_parts($pdo, $paymentId); pdf_receipt($r['payment'], $r['doc'], billing_company());
 * Returns null when the payment does not exist, or ['error'=>...] when its invoice is gone.
 * Keys: payment (+ method_label, paid_to_date, receipt_no), doc (balance as after this payment; customer from the client),
 * client, tenant, client_id, tenant_id, doc_id.
 */
function billing_receipt_parts(PDO $pdo, int $paymentId): ?array
{
    require_once __DIR__ . '/billing.php';
    $st = $pdo->prepare('SELECT * FROM cp_payments WHERE id = ?');
    $st->execute([$paymentId]);
    $payment = $st->fetch();
    if (!$payment) {
        return null;
    }
    $methods = function_exists('billing_payment_methods') ? billing_payment_methods() : [];
    $m = (string)($payment['method'] ?? '');
    $payment['method_label'] = $methods[$m] ?? ($m !== '' ? ucfirst(str_replace('_', ' ', $m)) : '');
    $tenantId = !empty($payment['tenant_id']) ? (int)$payment['tenant_id'] : null;
    $tenant = $tenantId && function_exists('tenant_find') ? tenant_find($pdo, $tenantId) : null;
    $doc = null;
    if (!empty($payment['document_id'])) {
        $doc = billing_doc($pdo, (int)$payment['document_id']);
        if (!$doc) {
            return ['error' => 'The invoice for this payment was not found.'];
        }
        $ps = $pdo->prepare('SELECT id, amount FROM cp_payments WHERE document_id = ? ORDER BY id');
        $ps->execute([(int)$doc['id']]);
        [$doc, $payment] = billing_mail_receipt_state($doc, $ps->fetchAll(), $payment);
        $tenantId = $tenantId ?: (!empty($doc['tenant_id']) ? (int)$doc['tenant_id'] : null);
        $tenant = $tenant ?: ($doc['tenant'] ?? null);
    } else {
        // A payment not against an invoice (a licence payment on the business page): a receipt for the amount alone.
        $amount = round((float)$payment['amount'], 2);
        $doc = ['id' => null, 'kind' => 'invoice', 'number' => '', 'status' => 'paid', 'currency' => (string)$payment['currency'],
            'total' => $amount, 'amount_paid' => $amount, 'balance' => 0.0, 'customer_name' => '', 'customer_contact' => '',
            'customer_address' => '', 'customer_email' => '', 'tenant_id' => $tenantId, 'tenant' => $tenant, 'items' => []];
        $payment['paid_to_date'] = $amount;
        if ((int)($payment['months'] ?? 0) > 0) {
            $payment['description'] = 'Licence payment: ' . (int)$payment['months'] . ' month' . ((int)$payment['months'] === 1 ? '' : 's')
                . (!empty($payment['paid_until_after']) && function_exists('billing_date') ? ', paid until ' . billing_date((string)$payment['paid_until_after']) : '') . '.';
        }
    }
    $client = !empty($payment['client_id']) ? billing_mail_client($pdo, (int)$payment['client_id']) : null;
    if (!$client && !empty($doc['id'])) {
        $client = billing_mail_doc_client($pdo, $doc);
    }
    if (!$client && $tenantId) {
        $client = billing_mail_client_for_tenant($pdo, $tenantId);
    }
    $doc = billing_mail_fill_customer($doc, $client);
    if (!isset($payment['receipt_no'])) {
        $payment['receipt_no'] = function_exists('billing_receipt_number') ? billing_receipt_number($payment, $doc)
            : trim((string)($doc['number'] ?? '') . '-' . (int)$payment['id'], '-');
    }
    return ['payment' => $payment, 'doc' => $doc, 'client' => $client, 'tenant' => $tenant, 'client_id' => $client ? (int)$client['id'] : null,
        'tenant_id' => $tenantId, 'doc_id' => !empty($doc['id']) ? (int)$doc['id'] : null];
}

/** When the document was last emailed (invoice / quote / regular invoice), from cp_email_log; null if never. */
function billing_last_sent(PDO $pdo, int $docId): ?string
{
    $st = $pdo->prepare(
        "SELECT MAX(created_at) FROM cp_email_log WHERE document_id = ? AND status IN ('sent','test')
         AND kind IN ('invoice','quote','recurring_invoice')"
    );
    $st->execute([$docId]);
    $v = $st->fetchColumn();
    return $v ? (string)$v : null;
}

/** [client row|null, tenant id|null] for a statement / payment request (client id; opts client_id or legacy tenant_id win). */
function billing_mail_account(PDO $pdo, int $id, array $opts): array
{
    $client = null;
    if (!empty($opts['client_id'])) {
        $client = billing_mail_client($pdo, (int)$opts['client_id']);
    } elseif (!empty($opts['tenant_id'])) {
        $client = billing_mail_client_for_tenant($pdo, (int)$opts['tenant_id']);
        if (!$client) {
            return [null, (int)$opts['tenant_id']];
        }
    } else {
        $client = billing_mail_client($pdo, $id);
    }
    return [$client, $client && !empty($client['tenant_id']) ? (int)$client['tenant_id'] : null];
}

/** Statement for a client (client_statement when loaded; else the business's statement); null when not possible. */
function billing_mail_statement(PDO $pdo, ?array $client, ?int $tenantId, ?string $from, ?string $until, ?string $currency): ?array
{
    if ($client && function_exists('client_statement')) {
        $st = client_statement($pdo, (int)$client['id'], $from, $until, $currency);
    } elseif ($tenantId) {
        $st = billing_statement($pdo, $tenantId, $from, $until, $currency);
    } else {
        return null;
    }
    if ($client) {
        $st['client'] = $st['client'] ?? $client;
        if (empty($st['tenant'])) {
            $st['tenant'] = billing_mail_client_party($client); // the PDF reads the customer block from 'tenant'
        }
    }
    return $st;
}

/** Summary of what a client owes (client_summary when loaded; else the business's). */
function billing_mail_summary(PDO $pdo, ?array $client, ?int $tenantId): ?array
{
    if ($client && function_exists('client_summary')) {
        return client_summary($pdo, (int)$client['id']);
    }
    return $tenantId ? billing_tenant_summary($pdo, $tenantId) : null;
}

/**
 * Prepares an email without sending it. $withPdf false = attachment names only (no TCPDF work; for previews).
 * Returns ['ok', 'error', 'log' (true when a failure should be logged), 'subject', 'html', 'text', 'attach' [[name, bytes, mime]],
 * 'names', 'to', 'cc', 'tenant_id', 'client_id', 'doc_id', 'doc'].
 */
function billing_mail_build(PDO $pdo, string $kind, int $id, array $opts, bool $withPdf = true): array
{
    $out = ['ok' => false, 'error' => '', 'log' => false, 'subject' => '', 'html' => '', 'text' => '', 'attach' => [], 'names' => [],
        'to' => [], 'cc' => [], 'tenant_id' => null, 'client_id' => null, 'doc_id' => null, 'doc' => null];
    $fail = function (string $error) use (&$out): array {
        $out['error'] = $error;
        return $out;
    };
    if (!in_array($kind, billing_mail_kinds(), true)) {
        return $fail('Unknown email type.');
    }
    require_once __DIR__ . '/billing.php';
    require_once __DIR__ . '/pdf.php';

    $company = billing_company();
    $tol = billing_mail_tolerance();
    $data = ['message' => trim((string)($opts['message'] ?? ''))];
    $doc = null;
    $tenant = null;
    $client = null;
    $attach = [];
    $add = function (string $title, string $number, callable $make) use (&$attach, $withPdf): void {
        $attach[] = ['name' => pdf_filename($title, $number), 'bytes' => $withPdf ? $make() : '', 'mime' => 'application/pdf'];
    };

    try {
        switch ($kind) {
            case 'invoice':
            case 'quote':
            case 'reminder':
            case 'recurring_invoice':
            case 'quote_accepted':
            case 'thank_you_business':
                $doc = billing_doc($pdo, $id);
                $want = in_array($kind, ['quote', 'quote_accepted'], true) ? 'quote' : 'invoice';
                if (!$doc || $doc['kind'] !== $want) {
                    return $fail($want === 'quote' ? 'Quotation not found.' : 'Invoice not found.');
                }
                if ($doc['kind'] === 'invoice' && $doc['status'] === 'void') {
                    return $fail('This invoice is void, so it cannot be sent.');
                }
                if ($kind === 'reminder') {
                    if ($doc['status'] !== 'sent' || (float)$doc['balance'] <= $tol) {
                        return $fail('A reminder can only be sent for a sent invoice that still has a balance due.');
                    }
                    $data['days'] = empty($doc['due_date']) ? null
                        : billing_mail_days_past_due((string)$doc['due_date'], (string)($opts['today'] ?? date('Y-m-d')));
                }
                if ($kind === 'thank_you_business' && ($doc['status'] !== 'paid' && (float)$doc['balance'] > $tol)) {
                    return $fail('This invoice is not paid in full yet.');
                }
                if ($kind === 'quote_accepted' && !in_array($doc['status'], ['accepted', 'converted'], true)) {
                    return $fail('This quotation is not marked as accepted.');
                }
                if ($kind === 'recurring_invoice') {
                    $title = trim((string)($opts['title'] ?? ''));
                    if ($title === '') {
                        $st = $pdo->prepare('SELECT title FROM cp_recurring WHERE last_document_id = ? ORDER BY id DESC LIMIT 1');
                        $st->execute([(int)$doc['id']]);
                        $title = (string)($st->fetchColumn() ?: '');
                    }
                    $data['title'] = $title;
                }
                $client = billing_mail_doc_client($pdo, $doc);
                $doc = billing_mail_fill_customer($doc, $client);
                $data['doc'] = $doc;
                $tenant = $doc['tenant'] ?? null;
                $out['tenant_id'] = $doc['tenant_id'] ? (int)$doc['tenant_id'] : null;
                $out['doc_id'] = (int)$doc['id'];
                $pdfDoc = $doc;
                $add($doc['kind'] === 'quote' ? 'Quotation' : 'Invoice', (string)$doc['number'], function () use ($pdfDoc, $company) {
                    return pdf_document($pdfDoc, $company);
                });
                break;

            case 'receipt':
            case 'thank_you_payment':
                $parts = billing_receipt_parts($pdo, $id);
                if (!$parts) {
                    return $fail('Payment not found.');
                }
                if (isset($parts['error'])) {
                    return $fail($parts['error']);
                }
                $doc = $parts['doc'];
                $client = $parts['client'];
                $tenant = $parts['tenant'];
                $data['payment'] = $parts['payment'];
                $data['doc'] = $doc;
                $out['tenant_id'] = $parts['tenant_id'];
                $out['doc_id'] = $parts['doc_id'];
                $pay = $parts['payment'];
                $add('Receipt', (string)$pay['receipt_no'], function () use ($pay, $doc, $company) {
                    return pdf_receipt($pay, $doc, $company);
                });
                break;

            case 'welcome':
                $client = billing_mail_client($pdo, $id);
                if (!$client) {
                    return $fail('Client not found.');
                }
                $data['client'] = $client;
                $data['currency'] = (string)($client['currency'] ?? billing_setting('default_currency'));
                $data['due_days'] = (int)billing_setting('invoice_due_days');
                $out['tenant_id'] = !empty($client['tenant_id']) ? (int)$client['tenant_id'] : null;
                break;

            case 'trial_started':
            case 'trial_ending':
            case 'trial_ended':
            case 'trial_converted':
                // Part 3. Checked again at send time: an Outbox row approved later must still be true.
                $st = $pdo->prepare('SELECT * FROM cp_tenants WHERE id = ?');
                $st->execute([$id]);
                $tenant = $st->fetch() ?: null;
                if (!$tenant) {
                    return $fail('Business not found.');
                }
                // The later of the day it was queued and today: an Outbox row approved days later never says "ends in 7 days" after the end.
                $today = max(isset($opts['today']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$opts['today']) ? (string)$opts['today'] : '', date('Y-m-d'));
                if ($kind === 'trial_converted') {
                    if (empty($tenant['trial_converted_at'])) {
                        return $fail('This business has not been converted from a free trial.');
                    }
                } elseif (empty($tenant['trial_until'])) {
                    return $fail('This business is not on a free trial.');
                } elseif (!empty($tenant['trial_converted_at']) && $kind !== 'trial_started') {
                    return $fail('This free trial was already converted to a paid plan.');
                } elseif ($kind === 'trial_ending' && (string)$tenant['trial_until'] < $today) {
                    return $fail('This free trial has already ended.');
                } elseif ($kind === 'trial_ended' && (string)$tenant['trial_until'] >= $today) {
                    return $fail('This free trial has not ended (it may have been extended).');
                }
                $client = billing_mail_client_for_tenant($pdo, $id);
                $data = trial_mail_data($pdo, $tenant, $kind, $today) + $data;
                $data['client'] = $client;
                $out['tenant_id'] = $id;
                break;

            case 'statement':
            case 'payment_request':
                [$client, $tenantId] = billing_mail_account($pdo, $id, $opts);
                if (!$client && !$tenantId) {
                    return $fail('Client not found.');
                }
                $out['tenant_id'] = $tenantId;
                $tenant = $tenantId ? tenant_find($pdo, $tenantId) : null;
                $data['client'] = $client;
                if ($kind === 'statement') {
                    $statement = billing_mail_statement($pdo, $client, $tenantId, $opts['from'] ?? null, $opts['until'] ?? null,
                        isset($opts['currency']) && $opts['currency'] !== null ? (string)$opts['currency'] : null);
                    if (!$statement) {
                        return $fail('A statement for this client needs the Clients module (app/clients.php).');
                    }
                    $data['statement'] = $statement;
                    $label = (string)($client['name'] ?? ($statement['tenant']['name'] ?? 'account')) . ' ' . (string)($statement['to'] ?? date('Y-m-d'));
                    $add('Statement', $label, function () use ($statement, $company) {
                        return pdf_statement($statement, $company);
                    });
                    break;
                }
                $sum = billing_mail_summary($pdo, $client, $tenantId);
                if (!$sum) {
                    return $fail('A payment request for this client needs the Clients module (app/clients.php).');
                }
                if (!empty($sum['other'])) {
                    return $fail('This client owes in more than one currency (' . implode(', ', array_merge([(string)$sum['currency']], array_keys($sum['other'])))
                        . '). Send each invoice or a statement per currency instead of one payment request.');
                }
                $open = [];
                foreach ((array)($sum['open'] ?? []) as $o) {
                    $full = billing_doc($pdo, (int)$o['id']);
                    if ($full && $full['kind'] === 'invoice' && $full['status'] === 'sent' && (float)$full['balance'] > $tol) {
                        $open[] = billing_mail_fill_customer($full, $client);
                    }
                }
                if (!$open) {
                    return $fail('Nothing is owed by this client, so there is no payment request to send.');
                }
                $statement = billing_mail_statement($pdo, $client, $tenantId, null, null, (string)($sum['currency'] ?? $open[0]['currency']));
                $data += ['tenant' => $tenant ?: ($statement['tenant'] ?? null), 'open' => $open, 'owed' => (float)$sum['owed'],
                    'overdue' => (float)($sum['overdue'] ?? 0), 'currency' => (string)($sum['currency'] ?? $open[0]['currency'])];
                foreach ($open as $o) {
                    $add('Invoice', (string)$o['number'], function () use ($o, $company) {
                        return pdf_document($o, $company);
                    });
                }
                if ($statement) {
                    $label = (string)($client['name'] ?? ($tenant['name'] ?? 'account')) . ' ' . date('Y-m-d');
                    $add('Statement', $label, function () use ($statement, $company) {
                        return pdf_statement($statement, $company);
                    });
                }
                break;
        }
        $mail = email_render($kind, $data, $company);
    } catch (Throwable $e) {
        error_log('billing_mail_build ' . $kind . ' #' . $id . ': ' . get_class($e) . ': ' . $e->getMessage());
        $out['log'] = true;
        $out['names'] = array_map(function ($a) {
            return $a['name'];
        }, $attach);
        $out['subject'] = billing_mail_kind_label($kind) . ' (not sent)';
        return $fail('The document or email could not be prepared: ' . $e->getMessage());
    }

    $out['client_id'] = $client ? (int)$client['id'] : null;
    [$out['to'], $out['cc']] = billing_mail_recipients($client, $tenant, $doc, $opts);
    $out['ok'] = true;
    $out['subject'] = $mail['subject'];
    $out['html'] = $mail['html'];
    $out['text'] = $mail['text'];
    $out['attach'] = $attach;
    $out['names'] = array_map(function ($a) {
        return $a['name'];
    }, $attach);
    $out['doc'] = $doc;
    return $out;
}

function billing_send(PDO $pdo, string $kind, int $id, array $opts, int $adminId): array
{
    $b = billing_mail_build($pdo, $kind, $id, $opts, true);
    if (!$b['ok']) {
        $logId = $b['log'] ? billing_mail_log($pdo, $b['tenant_id'], $b['client_id'], $b['doc_id'], $kind, [], $b['subject'], 'failed', $b['error'], $b['names'], $adminId) : 0;
        return ['ok' => false, 'error' => $b['error'], 'to' => [], 'test' => false, 'log_id' => $logId];
    }
    $tenantId = $b['tenant_id'];
    $clientId = $b['client_id'];
    $docId = $b['doc_id'];
    $doc = $b['doc'];
    $to = $b['to'];
    $cc = $b['cc'];
    if (!$to) {
        $err = $clientId
            ? 'No email address: add an email to the client, or type one in "To".'
            : ($tenantId ? 'No email address: add a billing email to the business, or type one in "To".'
                : 'No email address: add the customer\'s email to the document, or type one in "To".');
        $logId = billing_mail_log($pdo, $tenantId, $clientId, $docId, $kind, [], $b['subject'], 'failed', $err, $b['names'], $adminId);
        return ['ok' => false, 'error' => $err, 'to' => [], 'test' => false, 'log_id' => $logId];
    }

    $send = billing_mail_sender();
    try {
        $res = $send(['to' => $to, 'cc' => $cc, 'subject' => $b['subject'], 'html' => $b['html'], 'text' => $b['text'], 'attachments' => $b['attach']]);
    } catch (Throwable $e) {
        error_log('billing_send ' . $kind . ' #' . $id . ': ' . get_class($e));
        $res = ['ok' => false, 'error' => 'The email could not be sent.', 'to' => []];
    }
    $res += ['ok' => false, 'error' => '', 'to' => [], 'test' => false, 'subject' => $b['subject']];
    $status = !$res['ok'] ? 'failed' : ($res['test'] ? 'test' : 'sent');
    $logId = billing_mail_log($pdo, $tenantId, $clientId, $docId, $kind, array_merge($to, $cc), (string)$res['subject'], $status, (string)$res['error'], $b['names'], $adminId);

    $before = null;
    $after = ['email' => $kind, 'status' => $status, 'to' => array_merge($to, $cc), 'attachments' => $b['names'], 'log_id' => $logId,
        'client_id' => $clientId];
    if ($docId) {
        $after['document_id'] = $docId;
        $after['number'] = $doc['number'] ?? null;
    }
    if ($kind === 'receipt' || $kind === 'thank_you_payment') {
        $after['payment_id'] = $id;
    }
    if ($res['ok'] && $docId && in_array($kind, ['invoice', 'quote', 'reminder', 'recurring_invoice'], true)) {
        $before = ['status' => $doc['status'], 'sent_at' => $doc['sent_at'] ?? null, 'last_reminder_at' => $doc['last_reminder_at'] ?? null,
            'reminder_count' => (int)($doc['reminder_count'] ?? 0)];
        if ($kind === 'reminder') {
            $up = $pdo->prepare('UPDATE cp_documents SET last_reminder_at = ?, reminder_count = reminder_count + 1 WHERE id = ?');
            $after['reminder_count'] = $before['reminder_count'] + 1;
        } else {
            // sent_at is the FIRST time it was sent; a re-send keeps it (the last send is in cp_email_log: billing_last_sent()).
            $up = $pdo->prepare("UPDATE cp_documents SET sent_at = COALESCE(sent_at, ?), status = IF(status = 'draft', 'sent', status) WHERE id = ?");
            $after['doc_status'] = $doc['status'] === 'draft' ? 'sent' : $doc['status'];
        }
        $up->execute([date('Y-m-d H:i:s'), $docId]); // PHP time (APP_TZ): billing_due_reminders compares these with PHP's today
        if ($kind !== 'reminder' && ($doc['kind'] ?? '') === 'invoice' && $doc['status'] === 'draft' && function_exists('billing_settle_zero')) {
            billing_settle_zero($pdo, $docId, $adminId); // a zero-total invoice that is now sent is paid in full
        }
    }
    if (!$res['ok']) {
        $after['error'] = $res['error'];
    }
    audit_log($pdo, $adminId > 0 ? $adminId : null, 'billing_email_' . $kind, $tenantId, $before, $after);

    return ['ok' => (bool)$res['ok'], 'error' => (string)$res['error'], 'to' => (array)$res['to'], 'test' => (bool)$res['test'], 'log_id' => $logId];
}

/** Settings page "Send a test email": the branded test email to $to (MAIL_TEST_TO still wins), logged in cp_email_log. */
function billing_send_test(PDO $pdo, array $to, int $adminId): array
{
    $company = function_exists('billing_company') ? billing_company() : email_company_defaults();
    $mail = email_render('test', ['sent_at' => date('d/m/Y H:i')], $company);
    $to = mail_split($to);
    $send = billing_mail_sender();
    $res = $send(['to' => $to, 'cc' => [], 'subject' => $mail['subject'], 'html' => $mail['html'], 'text' => $mail['text']]);
    $res += ['ok' => false, 'error' => '', 'to' => [], 'test' => false, 'subject' => $mail['subject'], 'intended' => []];
    $status = !$res['ok'] ? 'failed' : ($res['test'] ? 'test' : 'sent');
    $logId = billing_mail_log($pdo, null, null, null, 'test', $res['intended'] ?: $res['to'], (string)$res['subject'], $status, (string)$res['error'], [], $adminId);
    return ['ok' => $res['ok'], 'error' => $res['error'], 'to' => $res['to'], 'test' => $res['test'], 'log_id' => $logId];
}
