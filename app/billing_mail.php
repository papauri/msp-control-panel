<?php
// Sends billing documents by email with the PDF attached (BILLING.md "Email"). Needs app/bootstrap.php loaded first.
// app/billing.php, app/pdf.php (TCPDF) and PHPMailer are loaded only when something is actually sent.
//
// billing_send(PDO, kind, id, opts, adminId): ['ok'=>bool, 'error'=>string, 'to'=>actual recipients, 'test'=>bool, 'log_id'=>int]
//   kind invoice | quote | reminder : id = cp_documents.id
//   kind payment_request            : id = any document id of the business, or pass $opts['tenant_id'] (wins over id)
//   kind statement                  : id = cp_tenants.id; optional $opts['from'], $opts['until'] (Y-m-d)
//   kind receipt                    : id = cp_payments.id (the payment must be against an invoice)
//   $opts: to (array|comma string; overrides the default recipients), cc (array|string; when the key is present it replaces the
//   default cc), message (personal note at the top), today (Y-m-d, for the reminder day count; default today).
// Every attempt is logged in cp_email_log (status sent | failed | test) and audited (no secrets).
// When MAIL_TEST_TO redirected the email (status test) the document is still marked sent / reminded, so the whole flow can be
// tested before go-live and cron does not repeat a reminder.

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/email_templates.php';

function billing_mail_tolerance(): float
{
    return defined('BILLING_TOLERANCE') ? (float)BILLING_TOLERANCE : 0.005;
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

/** Default recipients: the business's billing_email + cc_emails, or the document's customer_email (prospect / no billing email). */
function billing_mail_recipients(?array $tenant, ?array $doc, array $opts): array
{
    $to = [];
    $cc = [];
    if ($tenant) {
        $to = mail_split((string)($tenant['billing_email'] ?? ''));
        $cc = mail_split((string)($tenant['cc_emails'] ?? ''));
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

function billing_mail_log(PDO $pdo, ?int $tenantId, ?int $docId, string $kind, array $to, string $subject, string $status, string $error, array $attachments, int $adminId): int
{
    $st = $pdo->prepare(
        'INSERT INTO cp_email_log (tenant_id, document_id, kind, to_email, subject, status, error, attachment, admin_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $att = implode(', ', $attachments);
    $st->execute([
        $tenantId ?: null,
        $docId ?: null,
        substr($kind, 0, 30),
        substr(implode(', ', $to), 0, 500),
        mb_substr($subject, 0, 255, 'UTF-8'),
        $status,
        $error === '' ? null : mb_substr($error, 0, 500, 'UTF-8'),
        $att === '' ? null : mb_substr($att, 0, 190, 'UTF-8'),
        $adminId > 0 ? $adminId : null,
    ]);
    return (int)$pdo->lastInsertId();
}

function billing_send(PDO $pdo, string $kind, int $id, array $opts, int $adminId): array
{
    $fail = function (string $error) {
        return ['ok' => false, 'error' => $error, 'to' => [], 'test' => false, 'log_id' => 0];
    };
    if (!in_array($kind, ['invoice', 'quote', 'reminder', 'payment_request', 'statement', 'receipt'], true)) {
        return $fail('Unknown email type.');
    }
    require_once __DIR__ . '/billing.php';
    require_once __DIR__ . '/pdf.php';

    $company = billing_company();
    $tol = billing_mail_tolerance();
    $message = trim((string)($opts['message'] ?? ''));
    $data = ['message' => $message];
    $doc = null;
    $tenant = null;
    $tenantId = null;
    $docId = null;
    $attach = [];

    try {
        switch ($kind) {
            case 'invoice':
            case 'quote':
            case 'reminder':
                $doc = billing_doc($pdo, $id);
                $want = $kind === 'quote' ? 'quote' : 'invoice';
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
                    $today = (string)($opts['today'] ?? date('Y-m-d'));
                    $data['days'] = billing_mail_days_past_due((string)$doc['due_date'], $today);
                }
                $data['doc'] = $doc;
                $tenant = $doc['tenant'] ?? null;
                $tenantId = $doc['tenant_id'] ? (int)$doc['tenant_id'] : null;
                $docId = (int)$doc['id'];
                $attach[] = ['name' => pdf_filename($doc['kind'] === 'quote' ? 'Quotation' : 'Invoice', (string)$doc['number']),
                    'bytes' => pdf_document($doc, $company), 'mime' => 'application/pdf'];
                break;

            case 'payment_request':
                if (!empty($opts['tenant_id'])) {
                    $tenantId = (int)$opts['tenant_id'];
                } else {
                    $doc = billing_doc($pdo, $id);
                    if (!$doc) {
                        return $fail('Invoice not found.');
                    }
                    $docId = (int)$doc['id'];
                    $tenantId = $doc['tenant_id'] ? (int)$doc['tenant_id'] : null;
                }
                if (!$tenantId) {
                    return $fail('A payment request needs a business (this customer is a prospect): send the invoice instead.');
                }
                $sum = billing_tenant_summary($pdo, $tenantId);
                if (!empty($sum['other'])) {
                    return $fail('This business owes in more than one currency (' . implode(', ', array_merge([(string)$sum['currency']], array_keys($sum['other'])))
                        . '). Send each invoice or a statement per currency instead of one payment request.');
                }
                $open = [];
                foreach ((array)($sum['open'] ?? []) as $o) {
                    $full = billing_doc($pdo, (int)$o['id']);
                    if ($full && $full['kind'] === 'invoice' && $full['status'] === 'sent' && (float)$full['balance'] > $tol) {
                        $open[] = $full;
                    }
                }
                if (!$open) {
                    return $fail('Nothing is owed by this business, so there is no payment request to send.');
                }
                $statement = billing_statement($pdo, $tenantId);
                $tenant = $statement['tenant'] ?? ($open[0]['tenant'] ?? null);
                $data += ['tenant' => $tenant, 'open' => $open, 'owed' => (float)$sum['owed'], 'overdue' => (float)($sum['overdue'] ?? 0),
                    'currency' => (string)($sum['currency'] ?? $open[0]['currency'])];
                foreach ($open as $o) {
                    $attach[] = ['name' => pdf_filename('Invoice', (string)$o['number']), 'bytes' => pdf_document($o, $company), 'mime' => 'application/pdf'];
                }
                $attach[] = ['name' => pdf_filename('Statement', (string)($tenant['name'] ?? 'account') . ' ' . date('Y-m-d')),
                    'bytes' => pdf_statement($statement, $company), 'mime' => 'application/pdf'];
                break;

            case 'statement':
                $statement = billing_statement($pdo, $id, $opts['from'] ?? null, $opts['until'] ?? null, isset($opts['currency']) ? (string)$opts['currency'] : null);
                if (empty($statement['tenant'])) {
                    return $fail('Business not found.');
                }
                $tenant = $statement['tenant'];
                $tenantId = (int)$tenant['id'];
                $data['statement'] = $statement;
                $attach[] = ['name' => pdf_filename('Statement', (string)$tenant['name'] . ' ' . (string)($statement['to'] ?? date('Y-m-d'))),
                    'bytes' => pdf_statement($statement, $company), 'mime' => 'application/pdf'];
                break;

            case 'receipt':
                $st = $pdo->prepare('SELECT * FROM cp_payments WHERE id = ?');
                $st->execute([$id]);
                $payment = $st->fetch();
                if (!$payment) {
                    return $fail('Payment not found.');
                }
                if (empty($payment['document_id'])) {
                    return $fail('This payment is not against an invoice, so there is no receipt to send.');
                }
                $doc = billing_doc($pdo, (int)$payment['document_id']);
                if (!$doc) {
                    return $fail('The invoice for this payment was not found.');
                }
                $data['payment'] = $payment;
                $data['doc'] = $doc;
                $tenant = $doc['tenant'] ?? null;
                $tenantId = (int)$payment['tenant_id'] ?: ($doc['tenant_id'] ? (int)$doc['tenant_id'] : null);
                $docId = (int)$doc['id'];
                $attach[] = ['name' => pdf_filename('Receipt', (string)$doc['number'] . '-' . (int)$payment['id']),
                    'bytes' => pdf_receipt($payment, $doc, $company), 'mime' => 'application/pdf'];
                break;
        }
        $mail = email_render($kind, $data, $company);
    } catch (Throwable $e) {
        error_log('billing_send ' . $kind . ' #' . $id . ': ' . get_class($e) . ': ' . $e->getMessage());
        $err = 'The document or email could not be prepared: ' . $e->getMessage();
        $names = array_map(function ($a) {
            return $a['name'];
        }, $attach);
        $logId = billing_mail_log($pdo, $tenantId, $docId, $kind, [], ucfirst(str_replace('_', ' ', $kind)) . ' (not sent)', 'failed', $err, $names, $adminId);
        return ['ok' => false, 'error' => $err, 'to' => [], 'test' => false, 'log_id' => $logId];
    }

    [$to, $cc] = billing_mail_recipients($tenant, $doc, $opts);
    $names = array_map(function ($a) {
        return $a['name'];
    }, $attach);
    if (!$to) {
        $err = $tenantId
            ? 'No email address: add a billing email to the business, or type one in "To".'
            : 'No email address: add the customer\'s email to the document, or type one in "To".';
        $logId = billing_mail_log($pdo, $tenantId, $docId, $kind, [], $mail['subject'], 'failed', $err, $names, $adminId);
        return ['ok' => false, 'error' => $err, 'to' => [], 'test' => false, 'log_id' => $logId];
    }

    $res = mail_send(['to' => $to, 'cc' => $cc, 'subject' => $mail['subject'], 'html' => $mail['html'], 'text' => $mail['text'],
        'attachments' => $attach]);
    $status = !$res['ok'] ? 'failed' : ($res['test'] ? 'test' : 'sent');
    $logId = billing_mail_log($pdo, $tenantId, $docId, $kind, array_merge($to, $cc), $res['subject'], $status, $res['error'], $names, $adminId);

    $before = null;
    $after = ['email' => $kind, 'status' => $status, 'to' => array_merge($to, $cc), 'attachments' => $names, 'log_id' => $logId];
    if ($docId) {
        $after['document_id'] = $docId;
        $after['number'] = $doc['number'] ?? null;
    }
    if ($res['ok'] && $doc && in_array($kind, ['invoice', 'quote', 'reminder'], true)) {
        $before = ['status' => $doc['status'], 'sent_at' => $doc['sent_at'] ?? null, 'last_reminder_at' => $doc['last_reminder_at'] ?? null,
            'reminder_count' => (int)($doc['reminder_count'] ?? 0)];
        if ($kind === 'reminder') {
            $up = $pdo->prepare('UPDATE cp_documents SET last_reminder_at = ?, reminder_count = reminder_count + 1 WHERE id = ?');
            $after['reminder_count'] = $before['reminder_count'] + 1;
        } else {
            $up = $pdo->prepare("UPDATE cp_documents SET sent_at = ?, status = IF(status = 'draft', 'sent', status) WHERE id = ?");
            $after['doc_status'] = $doc['status'] === 'draft' ? 'sent' : $doc['status'];
        }
        $up->execute([date('Y-m-d H:i:s'), $docId]); // PHP time (APP_TZ): billing_due_reminders compares these with PHP's today
    }
    if (!$res['ok']) {
        $after['error'] = $res['error'];
    }
    audit_log($pdo, $adminId > 0 ? $adminId : null, 'billing_email_' . $kind, $tenantId, $before, $after);

    return ['ok' => $res['ok'], 'error' => $res['error'], 'to' => $res['to'], 'test' => $res['test'], 'log_id' => $logId];
}

/** Settings page "Send a test email": the branded test email to $to (MAIL_TEST_TO still wins), logged in cp_email_log. */
function billing_send_test(PDO $pdo, array $to, int $adminId): array
{
    $company = function_exists('billing_company') ? billing_company() : email_company_defaults();
    $mail = email_render('test', ['sent_at' => date('d/m/Y H:i')], $company);
    $to = mail_split($to);
    $res = mail_send(['to' => $to, 'cc' => [], 'subject' => $mail['subject'], 'html' => $mail['html'], 'text' => $mail['text']]);
    $status = !$res['ok'] ? 'failed' : ($res['test'] ? 'test' : 'sent');
    $logId = billing_mail_log($pdo, null, null, 'test', $res['intended'] ?: $res['to'], $res['subject'], $status, $res['error'], [], $adminId);
    return ['ok' => $res['ok'], 'error' => $res['error'], 'to' => $res['to'], 'test' => $res['test'], 'log_id' => $logId];
}
