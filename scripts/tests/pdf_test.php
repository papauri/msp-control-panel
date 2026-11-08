<?php
// Builds sample billing PDFs from fixed arrays (no database) into storage/tmp/ and checks them.
// Run: php scripts/tests/pdf_test.php
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/pdf.php';

$root = dirname(__DIR__, 2);
$out = $root . '/storage/tmp';
if (!is_dir($out)) {
    mkdir($out, 0700, true);
}

$company = [
    'name' => 'ProManaged IT',
    'address_lines' => ['Offaly,', 'Ireland'],
    'emails' => ['info@promanaged-it.com', 'sales@promanaged-it.com'],
    'phone' => '',
    'website' => 'https://promanaged-it.com',
    'payment_details' => "Eur/\$ Account\n-----------------\nBank Name: Permanent TSB\nAccount Name: PROMANAGED IT\n"
        . "IBAN: IE04IPBS99072135995698\nBIC: IPBSIE2D\nBank account number: 35995698\nBranch code: 990721\n"
        . "Bank Code: IPBS\nBranch Address: Address: 56-59 ST. STEPHEN'S GREEN, DUBLIN, D02 H489\n\nMWK Account\n"
        . "-----------------\nStandard Bank\nBranch: CAPITAL CITY\nAccount Name: JOHN PAUL CHIRWA\n"
        . "Account Numbers: 9100006400512\n----------------------------------\n"
        . "Please contact us for more information about payment options.",
    'thank_you' => 'Thank you for your business.',
    'salesperson' => 'John Paul',
    'brand_color' => '#D40000',
    'brand_navy' => '#1B3A5C',
    'logo_path' => $root . '/public/assets/logo.png',
];

function item(int $sort, $qty, string $item, string $desc, float $price): array
{
    return ['sort' => $sort, 'qty' => $qty, 'item' => $item, 'description' => $desc,
        'unit_price' => $price, 'line_total' => round($qty * $price, 2)];
}

// The owner's invoice 170133, line for line.
$invoice = [
    'id' => 1, 'kind' => 'invoice', 'number' => '170133', 'tenant_id' => null,
    'customer_name' => 'Liwonde Sun Hotel', 'customer_contact' => 'Yusuf', 'customer_email' => '', 'customer_address' => '',
    'status' => 'sent', 'issue_date' => '2026-01-17', 'due_date' => '2026-01-24', 'currency' => 'MWK',
    'salesperson' => 'John Paul', 'notes' => '', 'subtotal' => 602000.00, 'discount' => 0, 'tax_rate' => 0, 'tax' => 0,
    'total' => 602000.00, 'amount_paid' => 0, 'balance' => 602000.00, 'tenant' => null,
    'items' => [
        item(1, 1, 'Web Hosting | Billed Monthly', 'Web Hosting for X3 sites | Liwonde Sun Hotel | Zest Garden Lodge | Kanga Resort', 100000),
        item(2, 1, '.com Domain Registration (2 Years)', 'Domain Registration (Client renews after 2 Years) for http://zestgardenlodge.com/', 134000),
        item(3, 1, '.com Domain Registration (2 Years)', 'Domain Registration (Client renews after 2 Years) for http://kangaresort.com/', 134000),
        item(4, 1, '.com Domain Registration (2 Years)', 'Domain Registration (Client renews after 2 Years) for http://airlinkmw.com/', 134000),
        item(5, 1, 'Service Fee', '1 Time Service Fee', 100000),
    ],
];

// 30 lines, with a discount, VAT and a part payment.
$long = $invoice;
$long['number'] = '170200';
$long['items'] = [];
$sub = 0.0;
for ($i = 1; $i <= 30; $i++) {
    $it = item($i, $i % 3 + 1, 'Line ' . $i, 'Monthly licence and support, line ' . $i . ($i % 4 === 0 ? ' (includes on-site visit and training for front-office staff)' : ''), 25000 + $i * 1000);
    $long['items'][] = $it;
    $sub += $it['line_total'];
}
$long['subtotal'] = round($sub, 2);
$long['discount'] = 10000.00;
$long['tax_rate'] = 16.5;
$long['tax'] = round(($sub - 10000) * 16.5 / 100, 2);
$long['total'] = round($sub - 10000 + $long['tax'], 2);
$long['amount_paid'] = 50000.00;
$long['balance'] = round($long['total'] - 50000, 2);

$quote = $invoice;
$quote['kind'] = 'quote';
$quote['number'] = 'Q-1001';
$quote['status'] = 'sent';
$quote['issue_date'] = '2026-11-09';
$quote['due_date'] = '2026-12-09';
$quote['notes'] = 'Prices include set-up. Hosting is billed monthly from the go-live date.';

$statement = [
    'tenant' => ['id' => 7, 'name' => 'Liwonde Sun Hotel', 'contact_name' => 'Yusuf', 'billing_address' => "P.O. Box 12\nLiwonde, Malawi", 'currency' => 'MWK'],
    'from' => '2026-01-01', 'to' => '2026-11-09', 'currency' => 'MWK', 'opening' => 0.0, 'closing' => 402000.00,
    'lines' => [
        ['date' => '2026-01-17', 'type' => 'invoice', 'ref' => '170133', 'description' => 'Invoice 170133', 'debit' => 602000.00, 'credit' => 0, 'balance' => 602000.00],
        ['date' => '2026-02-03', 'type' => 'payment', 'ref' => 'FT26034XK', 'description' => 'Payment, bank transfer', 'debit' => 0, 'credit' => 200000.00, 'balance' => 402000.00],
    ],
    'open_invoices' => [],
];

$payment = ['id' => 42, 'tenant_id' => 7, 'document_id' => 1, 'amount' => 200000.00, 'currency' => 'MWK', 'months' => 0,
    'reference' => 'FT26034XK', 'method' => 'bank_transfer', 'created_at' => '2026-02-03 10:15:00'];
$paidDoc = $invoice;
$paidDoc['amount_paid'] = 200000.00;
$paidDoc['balance'] = 402000.00;

// Helpers.
t_eq('money', 'MWK602,000.00', pdf_money(602000, 'MWK'));
t_eq('money negative', '-MWK1,000.50', pdf_money(-1000.5, 'MWK'));
t_eq('money zero', 'MWK0.00', pdf_money(0, 'mwk'));
t_eq('date', '17/01/2026', pdf_date('2026-01-17'));
t_eq('date time', '03/02/2026', pdf_date('2026-02-03 10:15:00'));
t_eq('date empty', '', pdf_date(null));
t_eq('qty whole', '10', pdf_qty('10.00'));
t_eq('qty part', '1.5', pdf_qty('1.50'));
t_eq('filename', 'ProManaged IT Invoice 170133.pdf', pdf_filename('Invoice', '170133'));
t_eq('filename clean', 'ProManaged IT Statement Liwonde Sun Hotel 2026-11-09.pdf', pdf_filename('Statement', 'Liwonde/Sun: Hotel 2026-11-09'));

function pages(string $pdf): int
{
    return preg_match_all('#/Type\s*/Page(?!s)#', $pdf);
}

// 170133 with VAT and a one-line note must still fit on one page.
$vat = $invoice;
$vat['tax_rate'] = 16.5;
$vat['tax'] = round(602000 * 0.165, 2);
$vat['total'] = round(602000 + $vat['tax'], 2);
$vat['balance'] = $vat['total'];
$vat['notes'] = 'Hosting renews monthly; domains renew every two years.';

$files = [
    'sample-invoice.pdf' => [pdf_document($invoice, $company), 1],
    'sample-invoice-vat.pdf' => [pdf_document($vat, $company), 1],
    'sample-invoice-long.pdf' => [pdf_document($long, $company), 2],
    'sample-quote.pdf' => [pdf_document($quote, $company), 1],
    'sample-statement.pdf' => [pdf_statement($statement, $company), 1],
    'sample-receipt.pdf' => [pdf_receipt($payment, $paidDoc, $company), 1],
];
foreach ($files as $name => [$bytes, $wantPages]) {
    file_put_contents($out . '/' . $name, $bytes);
    t_ok("$name starts with %PDF", strncmp($bytes, '%PDF', 4) === 0);
    t_ok("$name > 5 KB", strlen($bytes) > 5120);
    t_eq("$name pages", $wantPages, pages($bytes));
    t_ok("$name title metadata", strpos($bytes, 'ProManaged IT') !== false);
}
echo "Wrote: " . implode(', ', array_map(function ($n) use ($out) { return $out . '/' . $n; }, array_keys($files))) . "\n";
t_done('pdf');
