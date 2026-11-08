<?php
// Branded billing emails (BILLING.md "Email"): the same look as the ProManaged IT invoice PDF. Pure: no DB, no network.
// Table-based HTML with inline CSS (email clients ignore <style>), max-width 640px, plus a plain-text alternative.
// The logo is referenced as cid:MAIL_LOGO_CID; app/mailer.php embeds public/assets/logo.png under that id.
//
// email_render(string $kind, array $data, array $company): ['subject','html','text']
//   Every kind: $data['message'] = optional personal note (shown on top, escaped).
//   invoice | quote : $data['doc']      = billing_doc() row (number, issue_date, due_date, currency, total, amount_paid,
//                                          balance, customer_name, customer_contact, status)
//   reminder        : $data['doc'] + $data['days'] = today minus the due date, as billing_due_reminders(): -3 => "due in 3 days",
//                                          0 => "due today", 7 => "7 days overdue" (billing_send computes it from due_date)
//   payment_request : $data['tenant'] (cp_tenants row), $data['open'] (open invoice docs), $data['owed'], $data['overdue'],
//                     $data['currency']
//   statement       : $data['statement'] = billing_statement() result
//   receipt         : $data['payment'] (cp_payments row: id, amount, currency, method, reference, created_at), $data['doc']
//   test            : nothing required ($data['sent_at'] optional)
// $company = billing_company() (email_company_defaults() is used for anything missing; 'email_signature' optional).

if (!defined('MAIL_LOGO_CID')) {
    define('MAIL_LOGO_CID', 'pmit-logo');
}

/** The contract defaults, used for any key billing_company() does not supply. */
function email_company_defaults(): array
{
    return [
        'name' => 'ProManaged IT',
        'address_lines' => ['Offaly,', 'Ireland'],
        'emails' => ['info@promanaged-it.com', 'sales@promanaged-it.com'],
        'phone' => '',
        'website' => 'https://promanaged-it.com',
        'payment_details' => '',
        'thank_you' => 'Thank you for your business.',
        'salesperson' => 'John Paul',
        'brand_color' => '#D40000',
        'brand_navy' => '#1B3A5C',
        'email_signature' => "John Paul\nProManaged IT\ninfo@promanaged-it.com",
    ];
}

function em_e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function em_money($amount, string $currency): string
{
    if (function_exists('billing_money')) {
        return billing_money((float)$amount, $currency);
    }
    $a = round((float)$amount, 2);
    return ($a < 0 ? '-' : '') . strtoupper($currency) . number_format(abs($a), 2, '.', ',');
}

function em_date($ymd): string
{
    if (function_exists('billing_date')) {
        return billing_date($ymd === null ? null : (string)$ymd);
    }
    $ymd = (string)$ymd;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m) || $ymd === '0000-00-00') {
        return '';
    }
    return $m[3] . '/' . $m[2] . '/' . $m[1];
}

/** "due in 3 days" / "due tomorrow" / "due today" / "1 day overdue" / "7 days overdue". */
function em_due_words(int $days): string
{
    if ($days === 0) {
        return 'due today';
    }
    if ($days === 1) {
        return 'due tomorrow';
    }
    if ($days > 1) {
        return 'due in ' . $days . ' days';
    }
    $n = -$days;
    return $n . ' day' . ($n === 1 ? '' : 's') . ' overdue';
}

function em_color(string $c, string $fallback): string
{
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $c) ? $c : $fallback;
}

function em_company(array $company): array
{
    $c = array_merge(email_company_defaults(), array_filter($company, function ($v) {
        return $v !== null;
    }));
    if (!isset($company['email_signature']) && function_exists('billing_setting')) {
        $sig = trim((string)billing_setting('email_signature'));
        if ($sig !== '') {
            $c['email_signature'] = $sig;
        }
    }
    foreach (['address_lines', 'emails'] as $k) {
        if (!is_array($c[$k])) {
            $c[$k] = preg_split('/\r\n|\r|\n/', (string)$c[$k]) ?: [];
        }
        $c[$k] = array_values(array_filter(array_map('trim', $c[$k]), 'strlen'));
    }
    $c['brand_color'] = em_color((string)$c['brand_color'], '#D40000');
    $c['brand_navy'] = em_color((string)$c['brand_navy'], '#1B3A5C');
    return $c;
}

function em_lines(string $s): array
{
    return preg_split('/\r\n|\r|\n/', trim($s)) ?: [];
}

function em_nl2br(string $s): string
{
    return implode('<br>', array_map('em_e', em_lines($s)));
}

function em_doc_balance(array $doc): float
{
    if (isset($doc['balance'])) {
        return round((float)$doc['balance'], 2);
    }
    return round((float)($doc['total'] ?? 0) - (float)($doc['amount_paid'] ?? 0), 2);
}

function em_greeting_name(array $doc, ?array $tenant = null): string
{
    foreach ([$doc['customer_contact'] ?? '', $tenant['contact_name'] ?? '', $doc['customer_name'] ?? '', $tenant['name'] ?? ''] as $n) {
        $n = trim((string)$n);
        if ($n !== '') {
            return $n;
        }
    }
    return 'Sir or Madam';
}

// ---------------------------------------------------------------- HTML building blocks

const EM_FONT = "font-family:Helvetica,Arial,sans-serif;";

function em_p(string $html, string $extra = ''): string
{
    return '<p style="margin:0 0 14px 0;' . EM_FONT . 'font-size:15px;line-height:1.55;color:#222222;' . $extra . '">' . $html . '</p>';
}

/** Two-column summary like the PDF's right block, alternating rows white / #E6E6E6. $rows: [[label, value, bold?]]. */
function em_summary(array $rows, array $c): string
{
    $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;border:1px solid #222222;margin:0 0 18px 0;">';
    $i = 0;
    foreach ($rows as $r) {
        $bg = $i++ % 2 ? '#E6E6E6' : '#FFFFFF';
        $b = !empty($r[2]) ? 'font-weight:bold;' : '';
        $h .= '<tr><td style="' . EM_FONT . 'font-size:14px;color:#444444;padding:8px 12px;background:' . $bg . ';border-bottom:1px solid #CCCCCC;width:45%;' . $b . '">'
            . em_e($r[0]) . '</td><td style="' . EM_FONT . 'font-size:14px;color:#111111;padding:8px 12px;background:' . $bg
            . ';border-bottom:1px solid #CCCCCC;text-align:right;' . $b . '">' . em_e($r[1]) . '</td></tr>';
    }
    return $h . '</table>';
}

/** A table with a red header row (white bold text), like the PDF's items table. $align: 'l'|'r' per column. */
function em_grid(array $head, array $align, array $rows, array $c, ?array $footer = null): string
{
    $th = EM_FONT . 'font-size:13px;font-weight:bold;color:#FFFFFF;background:' . $c['brand_color'] . ';padding:7px 8px;border:1px solid #222222;';
    $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 0 18px 0;"><tr>';
    foreach ($head as $i => $t) {
        $h .= '<td style="' . $th . 'text-align:' . ($align[$i] === 'r' ? 'right' : 'left') . ';">' . em_e($t) . '</td>';
    }
    $h .= '</tr>';
    foreach ($rows as $n => $row) {
        $bg = $n % 2 ? '#E6E6E6' : '#FFFFFF';
        $h .= '<tr>';
        foreach ($row as $i => $v) {
            $h .= '<td style="' . EM_FONT . 'font-size:13px;color:#111111;padding:7px 8px;border-left:1px solid #222222;border-right:1px solid #222222;background:'
                . $bg . ';text-align:' . ($align[$i] === 'r' ? 'right' : 'left') . ';white-space:nowrap;">' . em_e($v) . '</td>';
        }
        $h .= '</tr>';
    }
    if ($footer !== null) {
        $span = count($head) - 1;
        $h .= '<tr><td colspan="' . $span . '" style="' . EM_FONT . 'font-size:14px;font-weight:bold;padding:9px 8px;border:1px solid #222222;text-align:right;">'
            . em_e($footer[0]) . '</td><td style="' . EM_FONT . 'font-size:14px;font-weight:bold;padding:9px 8px;border:1px solid #222222;text-align:right;white-space:nowrap;">'
            . em_e($footer[1]) . '</td></tr>';
    } else {
        $h .= '<tr><td colspan="' . count($head) . '" style="border-top:1px solid #222222;font-size:0;line-height:0;">&nbsp;</td></tr>';
    }
    return $h . '</table>';
}

function em_note(string $message, array $c): string
{
    $message = trim($message);
    if ($message === '') {
        return '';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px 0;"><tr>'
        . '<td style="border-left:4px solid ' . $c['brand_navy'] . ';background:#F4F6F9;padding:12px 14px;' . EM_FONT
        . 'font-size:15px;line-height:1.55;color:#222222;">' . em_nl2br($message) . '</td></tr></table>';
}

function em_payment_details(array $c): string
{
    $pd = trim((string)$c['payment_details']);
    if ($pd === '') {
        return '';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 18px 0;"><tr>'
        . '<td style="' . EM_FONT . 'font-size:12px;line-height:1.5;font-weight:bold;color:#222222;padding:12px 14px;background:#FAFAFA;border:1px solid #E6E6E6;">'
        . '<span style="color:' . $c['brand_navy'] . ';font-size:13px;">How to pay</span><br>' . em_nl2br($pd) . '</td></tr></table>';
}

/** The whole email around $body: grey page, white card, title box + logo, company block, body, signature, footer. */
function em_layout(string $title, string $preheader, string $body, array $c, bool $payDetails): string
{
    $red = $c['brand_color'];
    $navy = $c['brand_navy'];
    $company = '<strong style="font-size:15px;color:#111111;">' . em_e($c['name']) . '</strong>';
    foreach (array_merge($c['address_lines'], $c['emails']) as $l) {
        $company .= '<br>' . em_e($l);
    }
    $sig = em_nl2br((string)$c['email_signature']);
    $foot = [];
    foreach ($c['emails'] as $em) {
        $foot[] = '<a href="mailto:' . em_e($em) . '" style="color:' . $navy . ';text-decoration:none;">' . em_e($em) . '</a>';
    }
    if (trim((string)$c['phone']) !== '') {
        $foot[] = em_e($c['phone']);
    }
    $web = trim((string)$c['website']);
    if ($web !== '' && preg_match('#^https?://#i', $web)) {
        $foot[] = '<a href="' . em_e($web) . '" style="color:' . $navy . ';text-decoration:none;">' . em_e(preg_replace('#^https?://#i', '', rtrim($web, '/'))) . '</a>';
    }

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="x-apple-disable-message-reformatting"><title>' . em_e($title) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#F2F2F2;-webkit-text-size-adjust:100%;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#F2F2F2;">' . em_e($preheader) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F2F2F2;"><tr><td align="center" style="padding:24px 10px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:640px;width:100%;background:#FFFFFF;border:1px solid #E0E0E0;">'
        // Title box + logo
        . '<tr><td style="padding:26px 28px 8px 28px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td valign="middle" align="left"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td style="border:2px solid ' . $red
        . ';padding:6px 12px;' . EM_FONT . 'font-size:24px;font-weight:bold;color:' . $red . ';">' . em_e($title) . '</td></tr></table></td>'
        . '<td valign="middle" align="right"><img src="cid:' . MAIL_LOGO_CID . '" width="84" alt="' . em_e($c['name']) . '" style="display:block;border:0;width:84px;height:auto;"></td>'
        . '</tr></table></td></tr>'
        // Company block with the grey bar
        . '<tr><td style="padding:14px 28px 6px 28px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td style="border-left:4px solid #CCCCCC;padding:2px 0 2px 12px;' . EM_FONT . 'font-size:13px;line-height:1.5;color:#444444;">' . $company . '</td>'
        . '</tr></table></td></tr>'
        // Body
        . '<tr><td style="padding:18px 28px 6px 28px;">' . $body
        . ($payDetails ? em_payment_details($c) : '')
        . em_p('<strong>' . em_e($c['thank_you']) . '</strong>')
        . em_p('Kind regards,<br>' . $sig, 'margin-bottom:22px;')
        . '</td></tr>'
        // Footer
        . '<tr><td style="background:' . $red . ';height:6px;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td align="center" style="padding:14px 20px 18px 20px;' . EM_FONT . 'font-size:12px;line-height:1.6;color:#666666;">'
        . '<strong style="color:' . $navy . ';">' . em_e($c['name']) . '</strong><br>' . implode(' &nbsp;|&nbsp; ', $foot) . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Plain-text alternative. $rows: [[label, value]]. */
function em_text(string $title, string $greeting, string $message, array $paras, array $rows, array $c, bool $payDetails, string $extra = ''): string
{
    $t = strtoupper($title) . ' - ' . $c['name'] . "\n\n";
    if (trim($message) !== '') {
        $t .= trim($message) . "\n\n";
    }
    $t .= $greeting . "\n\n";
    foreach ($paras as $p) {
        $t .= $p . "\n\n";
    }
    foreach ($rows as $r) {
        $t .= str_pad($r[0] . ':', 22) . $r[1] . "\n";
    }
    if ($rows) {
        $t .= "\n";
    }
    if ($extra !== '') {
        $t .= $extra . "\n\n";
    }
    if ($payDetails && trim((string)$c['payment_details']) !== '') {
        $t .= "HOW TO PAY\n" . trim((string)$c['payment_details']) . "\n\n";
    }
    $t .= $c['thank_you'] . "\n\nKind regards,\n" . trim((string)$c['email_signature']) . "\n\n--\n" . $c['name'] . "\n"
        . implode(' | ', array_merge($c['emails'], array_filter([trim((string)$c['phone']), trim((string)$c['website'])], 'strlen'))) . "\n";
    return $t;
}

// ---------------------------------------------------------------- the kinds

function email_render(string $kind, array $data, array $company): array
{
    $c = em_company($company);
    $msg = (string)($data['message'] ?? '');
    $doc = isset($data['doc']) && is_array($data['doc']) ? $data['doc'] : [];
    $cur = (string)($doc['currency'] ?? ($data['currency'] ?? 'MWK'));
    $num = (string)($doc['number'] ?? '');
    $balance = em_doc_balance($doc);
    $pay = true;
    $extraHtml = '';
    $extraText = '';

    switch ($kind) {
        case 'invoice':
            $title = 'Invoice';
            $name = em_greeting_name($doc, $doc['tenant'] ?? null);
            $subject = 'Invoice ' . $num . ' from ' . $c['name'];
            $paras = [
                'Please find attached invoice ' . $num . ' for ' . em_money($doc['total'] ?? 0, $cur)
                    . (em_date($doc['due_date'] ?? '') !== '' ? ', due on ' . em_date($doc['due_date']) : '') . '.',
                'The invoice is attached as a PDF. If you have any questions about it, simply reply to this email.',
            ];
            $rows = [['Invoice No.', $num], ['Date', em_date($doc['issue_date'] ?? '')], ['Due Date', em_date($doc['due_date'] ?? '')],
                ['Total', em_money($doc['total'] ?? 0, $cur)]];
            if ((float)($doc['amount_paid'] ?? 0) > 0.005) {
                $rows[] = ['Amount Paid', em_money($doc['amount_paid'], $cur)];
            }
            $rows[] = ['Balance Due', em_money($balance, $cur), true];
            break;

        case 'quote':
            $title = 'Quotation';
            $pay = false;
            $name = em_greeting_name($doc, $doc['tenant'] ?? null);
            $subject = 'Quotation ' . $num . ' from ' . $c['name'];
            $paras = [
                'Thank you for your interest. Please find attached our quotation ' . $num . ' for ' . em_money($doc['total'] ?? 0, $cur)
                    . (em_date($doc['due_date'] ?? '') !== '' ? ', valid until ' . em_date($doc['due_date']) : '') . '.',
                'To go ahead, or if you would like anything changed, simply reply to this email.',
            ];
            $rows = [['Quotation No.', $num], ['Date', em_date($doc['issue_date'] ?? '')], ['Valid Until', em_date($doc['due_date'] ?? '')],
                ['Total', em_money($doc['total'] ?? 0, $cur), true]];
            break;

        case 'reminder':
            $title = 'Reminder';
            $days = -(int)($data['days'] ?? 0); // days until due
            $words = em_due_words($days);
            $name = em_greeting_name($doc, $doc['tenant'] ?? null);
            if ($days < 0) {
                $subject = 'Overdue: invoice ' . $num . ' is ' . $words;
                $paras = [
                    'Our records show that invoice ' . $num . ' for ' . em_money($balance, $cur) . ' was due on ' . em_date($doc['due_date'] ?? '')
                        . ' and is now ' . $words . '.',
                    'We would be grateful if you could arrange payment at your earliest convenience. If you have already paid, thank you, and please '
                        . 'reply with the payment reference so we can match it. A copy of the invoice is attached.',
                ];
            } else {
                $subject = 'Reminder: invoice ' . $num . ' is ' . $words;
                $paras = [
                    'This is a friendly reminder that invoice ' . $num . ' for ' . em_money($balance, $cur) . ' is ' . $words
                        . ($days > 0 ? ' (' . em_date($doc['due_date'] ?? '') . ')' : '') . '.',
                    'If you have already arranged payment, thank you, and please ignore this reminder. A copy of the invoice is attached.',
                ];
            }
            $rows = [['Invoice No.', $num], ['Date', em_date($doc['issue_date'] ?? '')], ['Due Date', em_date($doc['due_date'] ?? '')],
                ['Status', ucfirst($words)], ['Balance Due', em_money($balance, $cur), true]];
            break;

        case 'payment_request':
            $title = 'Payment request';
            $tenant = (array)($data['tenant'] ?? []);
            $open = (array)($data['open'] ?? []);
            $cur = (string)($data['currency'] ?? ($tenant['currency'] ?? ($open[0]['currency'] ?? 'MWK')));
            $owed = isset($data['owed']) ? (float)$data['owed'] : array_sum(array_map('em_doc_balance', $open));
            $overdue = (float)($data['overdue'] ?? 0);
            $name = em_greeting_name([], $tenant);
            $subject = 'Payment request from ' . $c['name'] . ': ' . em_money($owed, $cur) . ' outstanding';
            $paras = [
                'Below is a summary of the invoices currently open on the account of ' . ($tenant['name'] ?? 'your business') . ', with a total of '
                    . em_money($owed, $cur) . ' outstanding' . ($overdue > 0.005 ? ', of which ' . em_money($overdue, $cur) . ' is overdue' : '') . '.',
                'We would be grateful if you could arrange payment. Each invoice and an up-to-date statement are attached. If you have already '
                    . 'paid, thank you, and please reply with the payment reference.',
            ];
            $grid = [];
            $textRows = [];
            foreach ($open as $d) {
                $dc = (string)($d['currency'] ?? $cur);
                $late = !empty($d['is_overdue']) ? ' (overdue)' : '';
                $grid[] = [(string)($d['number'] ?? ''), em_date($d['issue_date'] ?? ''), em_date($d['due_date'] ?? '') . $late,
                    em_money($d['total'] ?? 0, $dc), em_money(em_doc_balance($d), $dc)];
                $textRows[] = '  ' . ($d['number'] ?? '') . '  ' . em_date($d['issue_date'] ?? '') . '  due ' . em_date($d['due_date'] ?? '') . $late
                    . '  ' . em_money(em_doc_balance($d), $dc);
            }
            $extraHtml = em_grid(['Invoice No.', 'Date', 'Due Date', 'Total', 'Balance'], ['l', 'l', 'l', 'r', 'r'], $grid, $c,
                ['Total owed', em_money($owed, $cur)]);
            $extraText = "OPEN INVOICES\n" . implode("\n", $textRows) . "\n  Total owed: " . em_money($owed, $cur);
            $rows = [];
            break;

        case 'statement':
            $title = 'Statement';
            $st = (array)($data['statement'] ?? []);
            $tenant = (array)($st['tenant'] ?? []);
            $cur = (string)($st['currency'] ?? ($tenant['currency'] ?? 'MWK'));
            $name = em_greeting_name([], $tenant);
            $period = em_date($st['from'] ?? '') . ' - ' . em_date($st['to'] ?? '');
            $subject = 'Statement of account from ' . $c['name'] . ' (' . $period . ')';
            $closing = (float)($st['closing'] ?? 0);
            $paras = [
                'Please find attached the statement of account for ' . ($tenant['name'] ?? 'your business') . ' for ' . $period . '.',
                $closing > 0.005
                    ? 'The amount owed at the end of the period is ' . em_money($closing, $cur) . '. If anything does not match your records, simply reply to this email.'
                    : 'Your account is fully paid up at the end of the period. Thank you.',
            ];
            $rows = [['Account', (string)($tenant['name'] ?? '')], ['Period', $period], ['Opening balance', em_money($st['opening'] ?? 0, $cur)],
                ['Amount owed', em_money($closing, $cur), true]];
            $pay = $closing > 0.005;
            break;

        case 'receipt':
            $title = 'Receipt';
            $pay = false;
            $p = (array)($data['payment'] ?? []);
            $pc = (string)($p['currency'] ?? $cur);
            $name = em_greeting_name($doc, $doc['tenant'] ?? null);
            $subject = 'Receipt for your payment of ' . em_money($p['amount'] ?? 0, $pc) . ($num !== '' ? ' (invoice ' . $num . ')' : '');
            $paras = [
                'Thank you. We have received your payment of ' . em_money($p['amount'] ?? 0, $pc) . ($num !== '' ? ' against invoice ' . $num : '') . '.',
                $balance > 0.005
                    ? 'A balance of ' . em_money($balance, $cur) . ' remains on this invoice. Your receipt is attached.'
                    : 'This invoice is now paid in full. Your receipt is attached.',
            ];
            $rows = [['Receipt No.', 'R-' . (string)($p['id'] ?? '')], ['Date', em_date($p['created_at'] ?? '')], ['Invoice No.', $num],
                ['Amount Received', em_money($p['amount'] ?? 0, $pc), true]];
            if (trim((string)($p['method'] ?? '')) !== '') {
                $rows[] = ['Method', (string)$p['method']];
            }
            if (trim((string)($p['reference'] ?? '')) !== '') {
                $rows[] = ['Reference', (string)$p['reference']];
            }
            $rows[] = ['Balance Remaining', em_money($balance, $cur)];
            break;

        case 'test':
            $title = 'Test email';
            $pay = false;
            $name = 'there';
            $subject = 'Test email from ' . $c['name'];
            $paras = [
                'This is a test email from the ' . $c['name'] . ' licence panel.',
                'If you can read this, sending email is working and the branding below is how invoices, quotations and reminders will look.',
            ];
            $rows = [['Sent', (string)($data['sent_at'] ?? date('d/m/Y H:i'))]];
            break;

        default:
            throw new InvalidArgumentException('Unknown email kind: ' . $kind);
    }

    $greeting = 'Dear ' . $name . ',';
    $body = em_note($msg, $c) . em_p(em_e($greeting));
    foreach ($paras as $para) {
        $body .= em_p(em_e($para));
    }
    if ($rows) {
        $body .= em_summary($rows, $c);
    }
    $body .= $extraHtml;

    return [
        'subject' => trim(preg_replace('/[\r\n\t]+/', ' ', $subject)),
        'html' => em_layout($title, $paras[0], $body, $c, $pay),
        'text' => em_text($title, $greeting, $msg, $paras, $rows, $c, $pay, $extraText),
    ];
}
