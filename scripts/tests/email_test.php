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
$client = ['id' => 5, 'kind' => 'company', 'name' => 'Acme ' . $evil, 'contact_name' => 'Grace ' . $evil, 'email' => 'grace@acme.test',
    'cc_emails' => 'books@acme.test', 'currency' => 'MWK', 'address' => "Plot 1\nLilongwe"];
$paidDoc = array_merge($doc, ['status' => 'paid', 'amount_paid' => 602000, 'balance' => 0]);
$cases['thank_you_payment'] = $cases['receipt'];
$cases['thank_you_business'] = ['doc' => $paidDoc];
$cases['welcome'] = ['client' => $client, 'due_days' => 7, 'currency' => 'MWK'];
$cases['quote_accepted'] = ['doc' => array_merge($quote, ['status' => 'accepted'])];
$cases['recurring_invoice'] = ['doc' => $doc, 'title' => 'Website hosting ' . $evil];
// Part 3: free-trial emails (the business name carries the hostile string too).
$trial = ['tenant' => ['name' => 'Demo ' . $evil, 'contact_name' => 'Mary'], 'type' => 'Full hotel', 'modules' => ['Rooms'],
    'trial' => ['started' => '2026-11-09', 'until' => '2026-11-23', 'days_left' => 3, 'length' => 14, 'read_only_from' => '2026-11-24', 'locked_from' => '2026-11-27'],
    'offer' => [['Monthly', 'MWK100,000.00 a month']], 'plan' => ['title' => 'PMS licence', 'every' => 'every month', 'amount' => 'MWK100,000.00', 'first_invoice' => '170140']];
foreach (['trial_started', 'trial_ending', 'trial_ended', 'trial_converted'] as $k) {
    $cases[$k] = $trial;
}
t_eq('every template kind is tested', email_kinds(), array_values(array_intersect(email_kinds(), array_keys($cases))));

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
t_eq('recipients: billing + cc', [['b@x.com'], ['c@x.com']], billing_mail_recipients(null, ['billing_email' => 'b@x.com', 'cc_emails' => 'c@x.com, B@x.com'], null, []));
t_eq('recipients: prospect', [['p@y.com'], []], billing_mail_recipients(null, null, ['customer_email' => 'p@y.com'], []));
t_eq('recipients: override', [['o@z.com'], ['k@z.com']], billing_mail_recipients(null, ['billing_email' => 'b@x.com', 'cc_emails' => 'c@x.com'], null, ['to' => 'o@z.com', 'cc' => ['k@z.com']]));
t_eq('recipients: client wins', [['grace@acme.test'], ['books@acme.test']],
    billing_mail_recipients($client, ['billing_email' => 'b@x.com', 'cc_emails' => 'c@x.com'], ['customer_email' => 'p@y.com'], []));
t_eq('recipients: client without email falls back to tenant', [['b@x.com'], ['c@x.com']],
    billing_mail_recipients(['email' => '', 'cc_emails' => ''], ['billing_email' => 'b@x.com', 'cc_emails' => 'c@x.com'], null, []));
t_eq('recipients: individual client, no tenant', [['ind@x.com'], []], billing_mail_recipients(['kind' => 'individual', 'email' => 'ind@x.com'], null, null, []));

// ---------------------------------------------------------------- Part 2 kinds: wording, escaping, receipts
$ty = email_render('thank_you_payment', $cases['receipt'], $company);
t_eq('thank you payment subject', 'Thank you for your payment of MWK2,000.00', $ty['subject']);
t_ok('thank you payment: balance left + pay details', strpos($ty['html'], 'MWK600,000.00') !== false && strpos($ty['html'], 'Standard Bank') !== false);
t_ok('thank you payment: warm words', strpos($ty['text'], 'truly appreciate') !== false);
$tyFull = email_render('thank_you_payment', ['payment' => $cases['receipt']['payment'], 'doc' => $paidDoc], $company);
t_ok('thank you payment in full: no pay details', strpos($tyFull['html'], 'Standard Bank') === false && strpos($tyFull['text'], 'paid in full') !== false);
$tb = email_render('thank_you_business', ['doc' => $paidDoc], $company);
t_eq('thank you business subject', 'Thank you for your business: invoice 170134 is paid in full', $tb['subject']);
t_ok('thank you business: balance zero', strpos($tb['html'], 'MWK0.00') !== false);
$we = email_render('welcome', $cases['welcome'], $company);
t_eq('welcome subject', 'Welcome to ProManaged IT', $we['subject']);
t_ok('welcome: how billing works + due days', strpos($we['text'], 'how billing works') !== false && strpos($we['text'], 'within 7 days') !== false);
t_ok('welcome: payment details', strpos($we['html'], 'Standard Bank') !== false && strpos($we['text'], 'HOW TO PAY') !== false);
t_ok('welcome: client name escaped', strpos($we['html'], 'Acme &lt;script&gt;') !== false && strpos($we['html'], '<script>') === false);
t_ok('welcome: greets the contact', strpos($we['html'], 'Dear Grace &lt;script&gt;') !== false);
$weInd = email_render('welcome', ['client' => ['kind' => 'individual', 'name' => 'Mary Banda', 'email' => 'm@x.com'], 'due_days' => 1], $company);
t_ok('welcome individual: Dear name, 1 day', strpos($weInd['text'], 'Dear Mary Banda,') !== false && strpos($weInd['text'], 'within 1 day ') !== false);
$qa = email_render('quote_accepted', $cases['quote_accepted'], $company);
t_eq('quote accepted subject', 'Thank you for accepting quotation Q-1001', $qa['subject']);
t_ok('quote accepted: no pay details', strpos($qa['html'], 'Standard Bank') === false);
$ri = email_render('recurring_invoice', $cases['recurring_invoice'], $company);
t_eq('recurring subject', 'Your regular invoice 170134 for Website hosting ' . $evil . ' from ProManaged IT', $ri['subject']);
t_ok('recurring: title escaped in html', strpos($ri['html'], 'your regular invoice 170134 for Website hosting &lt;script&gt;') !== false);
t_ok('recurring: pay details', strpos($ri['html'], 'Standard Bank') !== false);

// Reminder with no due date: no "due today" wording.
$nodue = email_render('reminder', ['doc' => array_merge($doc, ['due_date' => null]), 'days' => null], $company);
t_eq('reminder without due date subject', 'Payment reminder: invoice 170134', $nodue['subject']);
t_ok('reminder without due date: no due words', strpos($nodue['text'], 'due today') === false && strpos($nodue['text'], 'overdue') === false);

// Method label instead of the raw key; receipt number matches the PDF.
$rk = email_render('receipt', ['payment' => ['id' => 12, 'amount' => 2000, 'currency' => 'MWK', 'method' => 'bank_transfer', 'reference' => 'X1',
    'created_at' => '2026-02-01'], 'doc' => $doc], $company);
t_ok('method label, not the key', strpos($rk['html'], 'Bank transfer') !== false && strpos($rk['html'], 'bank_transfer') === false);
t_ok('receipt number as the PDF', strpos($rk['html'], '170134-12') !== false);
t_eq('method label given wins', 'Mobile money', em_method_label(['method' => 'mobile_money', 'method_label' => 'Mobile money']));
$rl = email_render('receipt', ['payment' => ['id' => 9, 'amount' => 50, 'currency' => 'EUR', 'method' => 'cash', 'created_at' => '2026-02-01'],
    'doc' => ['number' => '', 'currency' => 'EUR', 'total' => 50, 'amount_paid' => 50, 'balance' => 0]], $company);
t_ok('receipt without invoice', strpos($rl['subject'], 'invoice') === false && strpos($rl['html'], 'Invoice No.') === false && strpos($rl['html'], 'EUR50.00') !== false);

// The receipt shows the balance right after THAT payment, not today's.
$invRow = ['id' => 7, 'kind' => 'invoice', 'status' => 'paid', 'total' => 1000, 'amount_paid' => 1000, 'balance' => 0];
$pays = [['id' => 3, 'amount' => 300], ['id' => 8, 'amount' => 200], ['id' => 11, 'amount' => 500]];
[$d1, $p1] = billing_mail_receipt_state($invRow, $pays, ['id' => 3, 'amount' => 300]);
t_eq('receipt doc: first part payment balance', 700.0, $d1['balance']);
t_eq('receipt doc: first part paid to date', 300.0, $p1['paid_to_date']);
t_eq('receipt doc: first part status', 'sent', $d1['status']);
[$d2] = billing_mail_receipt_state($invRow, $pays, ['id' => 8, 'amount' => 200]);
t_eq('receipt doc: second payment balance', 500.0, $d2['balance']);
[$d3] = billing_mail_receipt_state($invRow, $pays, ['id' => 11, 'amount' => 500]);
t_ok('receipt doc: last payment paid in full', abs($d3['balance']) < 0.005 && $d3['status'] === 'paid');
[$d4] = billing_mail_receipt_state(['total' => 0.3, 'kind' => 'invoice', 'status' => 'sent'], [['id' => 1, 'amount' => 0.1], ['id' => 2, 'amount' => 0.2]], ['id' => 2]);
t_ok('receipt doc: float-safe', abs($d4['balance']) < 0.005 && $d4['status'] === 'paid');

// Customer details come from the client when the document has none.
$filled = billing_mail_fill_customer(['customer_name' => '', 'customer_contact' => '', 'customer_address' => ''], $client);
t_eq('fill: name', $client['name'], $filled['customer_name']);
t_eq('fill: contact', $client['contact_name'], $filled['customer_contact']);
t_eq('fill: address', "Plot 1\nLilongwe", $filled['customer_address']);
$kept = billing_mail_fill_customer(['customer_name' => 'Own name', 'customer_contact' => '', 'customer_address' => 'Own addr'], $client);
t_ok('fill: never overwrites', $kept['customer_name'] === 'Own name' && $kept['customer_address'] === 'Own addr' && $kept['customer_contact'] === '');
$ind = billing_mail_fill_customer(['customer_name' => '', 'customer_contact' => ''], ['kind' => 'individual', 'name' => 'Mary Banda', 'contact_name' => 'x']);
t_ok('fill: individual has no separate contact', $ind['customer_name'] === 'Mary Banda' && $ind['customer_contact'] === '');
t_eq('client party for the PDF', 'Plot 1' . "\n" . 'Lilongwe', billing_mail_client_party($client)['billing_address']);

// Kinds and labels.
t_ok('send kinds include the new ones', !array_diff(['thank_you_payment', 'thank_you_business', 'welcome', 'quote_accepted', 'recurring_invoice'], billing_mail_kinds()));
t_eq('kind label', 'Thank you for your payment', billing_mail_kind_label('thank_you_payment'));

// The sender is swappable (tests never reach mail_send).
billing_mail_sender(function (array $m) {
    return ['ok' => true, 'error' => '', 'to' => $m['to'], 'test' => true];
});
t_ok('fake sender installed', billing_mail_sender() !== 'mail_send');
billing_mail_sender(null, true);
t_eq('sender restored', 'mail_send', billing_mail_sender());

// Long words in PDF cells break at / . - _ (fake width: 1 per character).
require __DIR__ . '/../../app/pdf.php';
$w = function (string $s): float {
    return (float)mb_strlen($s);
};
t_eq('wrap: short text untouched', 'Web hosting for one year', pdf_wrap_long('Web hosting for one year', 10, $w));
t_eq('wrap: domain breaks after dots and dashes', "(sensitive-\nsapphire-xyz.\nexample.com)",
    pdf_wrap_long('(sensitive-sapphire-xyz.example.com)', 13, $w));
t_eq('wrap: url after slashes', "https://\nexample.com/\npath/to/file", pdf_wrap_long('https://example.com/path/to/file', 12, $w));
t_eq('wrap: no break point = by characters', "abcde\nfghij\nk", pdf_wrap_long('abcdefghijk', 5, $w));
t_eq('wrap: keeps other words and lines', "Domain: a.b.c.d.\ne\nnext line", pdf_wrap_long("Domain: a.b.c.d.e\nnext line", 8, $w));
foreach (explode("\n", pdf_wrap_long('(sensitive-sapphire-xyz.example.com) and more words', 13, $w)) as $line) {
    foreach (explode(' ', $line) as $tok) {
        t_ok('wrap: every token fits (' . $tok . ')', mb_strlen($tok) <= 13);
    }
}
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
