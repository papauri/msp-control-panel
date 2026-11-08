<?php
// Pure tests for the billing emails: no database, no network.
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/mailer.php';
require __DIR__ . '/../../app/email_templates.php';
require __DIR__ . '/../../app/billing_mail.php';

$company = email_company_defaults();
$company['payment_details'] = "MWK Account\n-----------------\nStandard Bank";
$evil = '<script>alert(1)</script> & "quoted"';
$doc = [
    'id' => 7, 'kind' => 'invoice', 'number' => '170134', 'status' => 'sent', 'issue_date' => '2026-01-17', 'due_date' => '2026-01-24',
    'currency' => 'MWK', 'total' => 602000, 'amount_paid' => 0, 'balance' => 602000, 'customer_name' => 'Liwonde Sun Hotel',
    'customer_contact' => 'Yusuf ' . $evil, 'is_overdue' => false,
];
$quote = array_merge($doc, ['kind' => 'quote', 'number' => 'Q-1001', 'status' => 'draft']);
$tenant = ['id' => 3, 'name' => 'Liwonde Sun Hotel', 'contact_name' => 'Yusuf', 'currency' => 'MWK'];
$cases = [
    'invoice' => ['doc' => $doc],
    'quote' => ['doc' => $quote],
    'reminder' => ['doc' => $doc, 'days' => -3],
    'payment_request' => ['tenant' => $tenant, 'open' => [$doc, array_merge($doc, ['number' => '170135', 'balance' => 1000.5, 'is_overdue' => true])],
        'owed' => 603000.5, 'overdue' => 1000.5, 'currency' => 'MWK'],
    'statement' => ['statement' => ['tenant' => $tenant, 'from' => '2026-01-01', 'to' => '2026-11-09', 'opening' => 0, 'closing' => 602000,
        'currency' => 'MWK', 'lines' => [], 'open_invoices' => [$doc]]],
    'receipt' => ['payment' => ['id' => 12, 'amount' => 2000, 'currency' => 'MWK', 'method' => 'Bank transfer', 'reference' => 'REF ' . $evil,
        'created_at' => '2026-02-01 10:00:00'], 'doc' => array_merge($doc, ['amount_paid' => 2000, 'balance' => 600000])],
    'test' => [],
];

foreach ($cases as $kind => $data) {
    $data['message'] = 'Hello ' . $evil . "\nSecond line";
    $r = email_render($kind, $data, $company);
    t_ok("$kind: has subject", is_string($r['subject']) && $r['subject'] !== '' && strpos($r['subject'], "\n") === false);
    t_ok("$kind: has html", strpos($r['html'], '<html') !== false && strpos($r['html'], 'max-width:640px') !== false);
    t_ok("$kind: has text", strlen($r['text']) > 50 && strpos($r['text'], '<td') === false);
    t_ok("$kind: note escaped in html", strpos($r['html'], '<script>') === false && strpos($r['html'], '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;quoted&quot;') !== false);
    t_ok("$kind: note on top", strpos($r['html'], 'Hello &lt;script') < strpos($r['html'], 'Dear '));
    t_ok("$kind: note in text", strpos($r['text'], 'Hello ' . $evil) !== false);
    t_ok("$kind: cid logo", strpos($r['html'], 'src="cid:' . MAIL_LOGO_CID . '"') !== false);
    t_ok("$kind: brand red", strpos($r['html'], '#D40000') !== false);
}

// Amount and date formats.
$inv = email_render('invoice', ['doc' => $doc], $company);
t_ok('money format', strpos($inv['html'], 'MWK602,000.00') !== false);
t_ok('date format', strpos($inv['html'], '24/01/2026') !== false);
t_ok('invoice subject', $inv['subject'] === 'Invoice 170134 from ProManaged IT');
t_ok('payment details on invoice', strpos($inv['html'], 'Standard Bank') !== false);
t_ok('no payment details on quote', strpos(email_render('quote', ['doc' => $quote], $company)['html'], 'Standard Bank') === false);
t_ok('no note box without message', strpos($inv['html'], 'background:#F4F6F9') === false);
t_ok('customer name escaped', strpos($inv['html'], 'Dear Yusuf &lt;script&gt;') !== false);
$rec = email_render('receipt', $cases['receipt'], $company);
t_ok('receipt reference escaped', strpos($rec['html'], 'REF &lt;script&gt;') !== false && strpos($rec['html'], '<script>') === false);
$pr = email_render('payment_request', $cases['payment_request'], $company);
t_ok('payment request total owed', strpos($pr['html'], 'Total owed') !== false && strpos($pr['html'], 'MWK603,000.50') !== false);
t_ok('payment request lists both', strpos($pr['html'], '170134') !== false && strpos($pr['html'], '170135') !== false);
t_ok('payment request text owing', strpos($pr['text'], 'Total owed: MWK603,000.50') !== false);

// Reminder wording before / on / after the due date.
t_eq('words +3', 'due in 3 days', em_due_words(3));
t_eq('words +1', 'due tomorrow', em_due_words(1));
t_eq('words 0', 'due today', em_due_words(0));
t_eq('words -1', '1 day overdue', em_due_words(-1));
t_eq('words -7', '7 days overdue', em_due_words(-7));
t_eq('reminder before subject', 'Reminder: invoice 170134 is due in 3 days', email_render('reminder', ['doc' => $doc, 'days' => -3], $company)['subject']);
t_eq('reminder on subject', 'Reminder: invoice 170134 is due today', email_render('reminder', ['doc' => $doc, 'days' => 0], $company)['subject']);
t_eq('reminder after subject', 'Overdue: invoice 170134 is 7 days overdue', email_render('reminder', ['doc' => $doc, 'days' => 7], $company)['subject']);
t_eq('days before due', -3, billing_mail_days_past_due('2026-01-24', '2026-01-21'));
t_eq('days on due', 0, billing_mail_days_past_due('2026-01-24', '2026-01-24'));
t_eq('days overdue across year', 7, billing_mail_days_past_due('2025-12-28', '2026-01-04'));
t_eq('recipients: billing + cc', [['b@x.com'], ['c@x.com']], billing_mail_recipients(['billing_email' => 'b@x.com', 'cc_emails' => 'c@x.com, B@x.com'], null, []));
t_eq('recipients: prospect', [['p@y.com'], []], billing_mail_recipients(null, ['customer_email' => 'p@y.com'], []));
t_eq('recipients: override', [['o@z.com'], ['k@z.com']], billing_mail_recipients(['billing_email' => 'b@x.com', 'cc_emails' => 'c@x.com'], null, ['to' => 'o@z.com', 'cc' => ['k@z.com']]));
t_eq('fallback money negative', '-MWK1,234.50', em_money(-1234.5, 'MWK'));
t_eq('fallback date', '17/01/2026', em_date('2026-01-17 09:00:00'));
t_eq('fallback date empty', '', em_date(null));

// Unknown kind refused.
$threw = false;
try {
    email_render('spam', [], $company);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
t_ok('unknown kind throws', $threw);

// Bad brand colours fall back (no CSS injection).
$bad = $company;
$bad['brand_color'] = 'red;background:url(x)';
t_ok('bad colour ignored', strpos(email_render('test', [], $bad)['html'], 'url(x)') === false);

// Recipients: the test redirect.
$r = mail_recipients(['a@b.com', 'bad address', 'A@B.com'], ['c@d.com', 'a@b.com'], "Invoice 1\r\nBcc: x@y.com", ['johnpaulchirwa@gmail.com']);
t_eq('test: only the test address', ['johnpaulchirwa@gmail.com'], $r['to']);
t_eq('test: no cc', [], $r['cc']);
t_ok('test flag', $r['test'] === true);
t_eq('test: subject', '[TEST to a@b.com, c@d.com] Invoice 1 Bcc: x@y.com', $r['subject']);
t_eq('test: intended', ['a@b.com', 'c@d.com'], $r['intended']);
$r = mail_recipients(['a@b.com'], ['c@d.com', 'a@b.com'], 'Invoice 1', []);
t_eq('live: to', ['a@b.com'], $r['to']);
t_eq('live: cc without duplicates', ['c@d.com'], $r['cc']);
t_eq('live: subject untouched', 'Invoice 1', $r['subject']);
t_ok('live flag', $r['test'] === false);
t_eq('split string', ['x@y.com', 'z@w.org'], mail_split('x@y.com, z@w.org;bogus'));
t_eq('secure 465', 'ssl', mail_secure_for(465, ''));
t_eq('secure 587', 'tls', mail_secure_for(587, ''));
t_eq('secure explicit', 'tls', mail_secure_for(465, 'tls'));

// Password never leaks through an error string.
$cfg = ['host' => 'smtp.example.com', 'port' => 465, 'user' => 'u@example.com', 'pass' => 'S3cretPw!', 'secure' => 'ssl',
    'from_email' => 'info@promanaged-it.com', 'from_name' => 'ProManaged IT', 'test_to' => []];
$e = mail_plain_error('SMTP Error: Could not authenticate. pass=S3cretPw!', $cfg);
t_ok('auth error plain words', strpos($e, 'refused the username or password') !== false);
t_ok('password masked', strpos($e, 'S3cretPw!') === false);
t_ok('connect error plain words', strpos(mail_plain_error('SMTP Error: Could not connect to SMTP host.', $cfg), 'Could not reach the mail server') !== false);

// The built MIME message embeds the logo under the same CID (built only, nothing sent).
$m = ['subject' => 'Invoice 170134', 'html' => $inv['html'], 'text' => $inv['text'],
    'attachments' => [['name' => 'ProManaged IT Invoice 170134.pdf', 'bytes' => '%PDF-1.4 test', 'mime' => 'application/pdf']]];
$rr = mail_recipients(['a@b.com'], [], 'Invoice 170134', ['johnpaulchirwa@gmail.com']);
$mail = mail_prepare($m, $cfg, $rr);
$mail->preSend();
$mime = $mail->getSentMIMEMessage();
t_ok('mime: inline logo cid', strpos($mime, 'Content-ID: <' . MAIL_LOGO_CID . '>') !== false);
t_ok('mime: pdf attached', strpos($mime, 'ProManaged IT Invoice 170134.pdf') !== false);
t_ok('mime: only test recipient', strpos($mime, 'To: johnpaulchirwa@gmail.com') !== false && strpos($mime, 'a@b.com>') === false);
t_ok('mime: no password', strpos($mime, 'S3cretPw!') === false);
t_ok('mime: from', strpos($mime, 'info@promanaged-it.com') !== false);
t_eq('timeout', 20, $mail->Timeout);

t_done('email');
