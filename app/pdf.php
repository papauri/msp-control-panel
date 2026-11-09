<?php
// Billing PDFs (invoice, quotation, statement, receipt) in the ProManaged IT invoice template.
// Takes plain arrays only (no database, no billing_* calls) so it can be tested on its own.
// Contract: BILLING.md "PDF" and "Template". A4 portrait, Helvetica (core font, nothing embedded).

require_once __DIR__ . '/../lib/tcpdf/tcpdf.php';

const PDF_L = 16.0;          // left margin (mm)
const PDF_W = 178.0;         // content width (mm)
const PDF_TOP = 12.0;        // top margin (mm)
const PDF_LIMIT = 288.0;     // lowest y content may reach on a page (A4 = 297mm)
const PDF_TP = 5.2;          // totals line pitch
const PDF_DP = 3.4;          // payment details line pitch
const PDF_RP = 5.2;          // remittance line pitch
const PDF_TOL = 0.005;       // money tolerance (same as BILLING_TOLERANCE)

// ---------------------------------------------------------------- formatting helpers

function pdf_money($amount, string $currency): string
{
    $a = round((float)$amount, 2);
    $sign = $a < -PDF_TOL ? '-' : '';
    return $sign . strtoupper(trim($currency)) . number_format(abs($a), 2, '.', ',');
}

function pdf_number($amount): string
{
    $a = round((float)$amount, 2);
    return ($a < -PDF_TOL ? '-' : '') . number_format(abs($a), 2, '.', ',');
}

function pdf_date(?string $ymd): string
{
    $ymd = trim((string)$ymd);
    if ($ymd === '' || strpos($ymd, '0000-00-00') === 0) {
        return '';
    }
    $ts = strtotime($ymd);
    return $ts === false ? '' : date('d/m/Y', $ts);
}

function pdf_qty($qty): string
{
    $q = (float)$qty;
    if (abs($q - round($q)) < PDF_TOL) {
        return number_format(round($q), 0, '.', ',');
    }
    return rtrim(rtrim(number_format($q, 2, '.', ','), '0'), '.');
}

function pdf_lines(?string $text): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

function pdf_rgb(?string $hex, array $fallback): array
{
    $hex = ltrim(trim((string)$hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        return $fallback;
    }
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function pdf_filename(string $title, string $number): string
{
    $clean = function (string $s): string {
        $s = preg_replace('/[^A-Za-z0-9 ._-]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', (string)$s), ' .');
    };
    $name = trim('ProManaged IT ' . $clean($title) . ' ' . $clean($number));
    return $name . '.pdf';
}

/**
 * Long unbroken words (domains, URLs, file names) wider than the column are split at sensible points (after / . - _) into
 * pieces that fit, joined with new lines; a piece with no such point that is still too wide is split by characters (the
 * only case where a word is cut mid-way). Normal words that fit are never touched. $width(string): float measures text in
 * the current font. Pure apart from $width.
 */
function pdf_wrap_long(string $text, float $wmax, callable $width): string
{
    if ($wmax <= 0 || $text === '') {
        return $text;
    }
    $lines = preg_split('/\r\n|\r|\n/', $text);
    foreach ($lines as $li => $line) {
        $tokens = explode(' ', $line);
        foreach ($tokens as $ti => $tok) {
            if ($tok === '' || $width($tok) <= $wmax) {
                continue;
            }
            $segments = preg_split('#(?<=[/._-])#u', $tok, -1, PREG_SPLIT_NO_EMPTY) ?: [$tok];
            $pieces = [];
            $cur = '';
            foreach ($segments as $seg) {
                if ($width($seg) > $wmax) {
                    if ($cur !== '') {
                        $pieces[] = $cur;
                        $cur = '';
                    }
                    foreach (preg_split('//u', $seg, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                        if ($cur !== '' && $width($cur . $ch) > $wmax) {
                            $pieces[] = $cur;
                            $cur = '';
                        }
                        $cur .= $ch;
                    }
                    continue;
                }
                if ($cur !== '' && $width($cur . $seg) > $wmax) {
                    $pieces[] = $cur;
                    $cur = '';
                }
                $cur .= $seg;
            }
            if ($cur !== '') {
                $pieces[] = $cur;
            }
            $tokens[$ti] = implode("\n", $pieces);
        }
        $lines[$li] = implode(' ', $tokens);
    }
    return implode("\n", $lines);
}

// ---------------------------------------------------------------- frame

/** One line of text at (x, y); never wraps (squeezes horizontally if too long), never shrinks the font. */
function pdf__line(TCPDF $pdf, float $x, float $y, float $w, string $txt, string $align = 'L', float $h = 0.0): void
{
    $pdf->SetXY($x, $y);
    $pdf->Cell($w, $h, $txt, 0, 0, $align, false, '', 1, false, 'T', 'M');
}

/** TCPDF without its "Powered by TCPDF" link on the last page (the constructor re-enables it). */
class PmPdf extends TCPDF
{
    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false, false);
        $this->tcpdflink = false;
    }
}

function pdf__new(string $title, string $number, array $company): TCPDF
{
    $pdf = new PmPdf();
    $pdf->SetCreator('ProManaged IT');
    $pdf->SetAuthor('ProManaged IT');
    $pdf->SetTitle(trim('ProManaged IT ' . $title . ' ' . $number));
    $pdf->SetSubject($title);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(PDF_L, PDF_TOP, 210 - PDF_L - PDF_W);
    $pdf->SetAutoPageBreak(false, 297 - PDF_LIMIT);
    $pdf->setCellHeightRatio(1.2);
    $pdf->setCellPaddings(0, 0, 0, 0);
    $pdf->SetLineWidth(0.25);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->AddPage();
    return $pdf;
}

function pdf__red(array $company): array
{
    return pdf_rgb($company['brand_color'] ?? '', [212, 0, 0]);
}

/**
 * Title box, logo, company block (grey bar), meta block, customer block.
 * $meta: list of [label, value]. $stamp: optional status word next to the title.
 * Returns the y where the table starts.
 */
function pdf__top(TCPDF $pdf, string $title, array $company, array $meta, array $customer, string $stamp = ''): float
{
    $red = pdf__red($company);
    $navy = pdf_rgb($company['brand_navy'] ?? '', [27, 58, 92]);

    // Title in a red-outlined box.
    $pdf->SetFont('helvetica', 'B', 21.5);
    $boxW = $pdf->GetStringWidth($title) + 6.6;
    $pdf->SetLineWidth(0.6);
    $pdf->SetDrawColor($red[0], $red[1], $red[2]);
    $pdf->Rect(PDF_L, PDF_TOP, $boxW, 15, 'D');
    $pdf->SetTextColor($red[0], $red[1], $red[2]);
    $pdf->MultiCell($boxW, 15, $title, 0, 'C', false, 0, PDF_L, PDF_TOP, true, 0, false, true, 15, 'M');
    $pdf->SetLineWidth(0.25);
    $pdf->SetDrawColor(0, 0, 0);

    if ($stamp !== '') {
        $c = $stamp === 'PAID' ? $navy : $red;
        $pdf->SetTextColor($c[0], $c[1], $c[2]);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->MultiCell(40, 15, $stamp, 0, 'L', false, 0, PDF_L + $boxW + 6, PDF_TOP, true, 0, false, true, 15, 'M');
    }
    $pdf->SetTextColor(0, 0, 0);

    // Logo top-right, left edge on the meta value column (as in the template).
    $metaLabelX = PDF_L + 103.6;
    $metaValueX = PDF_L + 139.2;
    $logo = (string)($company['logo_path'] ?? '');
    if ($logo !== '' && is_file($logo)) {
        $pdf->Image($logo, $metaValueX + 0.5, PDF_TOP + 4.5, 17, 0, '', '', '', true, 300);
    }

    // Company block with a grey bar on its left edge.
    $y0 = 31.5;
    $tx = PDF_L + 3.8;
    $y = $y0 + 1.0;
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->MultiCell(95, 5, (string)($company['name'] ?? ''), 0, 'L', false, 1, $tx, $y);
    $y += 6.0;
    $pdf->SetFont('helvetica', '', 10.5);
    $lines = array_values(array_merge(
        (array)($company['address_lines'] ?? []),
        (array)($company['emails'] ?? []),
        trim((string)($company['phone'] ?? '')) !== '' ? [trim((string)$company['phone'])] : []
    ));
    foreach ($lines as $line) {
        pdf__line($pdf, $tx, $y, 95, (string)$line);
        $y += 4.3;
    }
    $companyBottom = $y + 0.8;
    $pdf->SetFillColor(217, 217, 217);
    $pdf->Rect(PDF_L, $y0, 1.3, $companyBottom - $y0, 'F');

    // Meta block, two columns.
    $my = $y0 + 1.5;
    $pdf->SetFont('helvetica', '', 10.5);
    foreach ($meta as $row) {
        pdf__line($pdf, $metaLabelX, $my, $metaValueX - $metaLabelX - 1, (string)$row[0]);
        pdf__line($pdf, $metaValueX, $my, PDF_L + PDF_W - $metaValueX, (string)$row[1]);
        $my += 4.1;
    }

    // Customer block.
    $y = max($companyBottom, $my) + 3.5;
    $pdf->SetFont('helvetica', '', 10.5);
    foreach ($customer as $line) {
        pdf__line($pdf, PDF_L + 1.8, $y, 100, (string)$line);
        $y += 4.4;
    }
    return $y + 3.0;
}

/**
 * Bordered table: red header row, alternating white/grey rows, vertical rules, outer border,
 * manual page breaks with the header repeated. $cols: [['label','w','align'], ...] (w in mm).
 * $minBottom: the table's body extends at least to this y (capped by $maxBottom, never forcing a break).
 * Returns ['bottom' => y of the bottom border].
 */
function pdf__table(TCPDF $pdf, array $company, array $cols, array $rows, float $y, float $minBottom, float $maxBottom, string $contLabel): array
{
    $red = pdf__red($company);
    $headH = 7.4;
    $edges = [PDF_L];
    $x = PDF_L;
    foreach ($cols as $c) {
        $x += $c['w'];
        $edges[] = $x;
    }

    $drawHeader = function (float $y) use ($pdf, $cols, $edges, $red, $headH): float {
        $pdf->SetFillColor($red[0], $red[1], $red[2]);
        $pdf->Rect(PDF_L, $y, PDF_W, $headH, 'DF');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 9.5);
        $pdf->setCellPaddings(1.6, 0, 1.6, 0);
        foreach ($cols as $i => $c) {
            $pdf->MultiCell($c['w'], $headH, $c['label'], 0, $c['align'], false, 0, $edges[$i], $y, true, 0, false, true, $headH, 'M', true);
        }
        $pdf->setCellPaddings(0, 0, 0, 0);
        $pdf->SetTextColor(0, 0, 0);
        for ($i = 1; $i < count($edges) - 1; $i++) {
            $pdf->Line($edges[$i], $y, $edges[$i], $y + $headH);
        }
        return $y + $headH;
    };
    $verticals = function (float $top, float $bottom) use ($pdf, $edges): void {
        if ($bottom - $top <= 0.01) {
            return;
        }
        foreach ($edges as $ex) {
            $pdf->Line($ex, $top, $ex, $bottom);
        }
    };

    $y = $drawHeader($y);
    $startPage = $pdf->getPage();
    $pdf->SetFont('helvetica', '', 9.5);
    foreach (array_values($rows) as $n => $row) {
        $pdf->setCellPaddings(1.6, 1.4, 1.6, 1.0);
        $h = 6.8;
        foreach ($cols as $i => $c) {
            // Long domains / URLs break after / . - _ instead of mid-word (cell padding 1.6 + 1.6, small safety margin).
            $row[$i] = pdf_wrap_long((string)($row[$i] ?? ''), $c['w'] - 3.6, function (string $t) use ($pdf): float {
                return (float)$pdf->GetStringWidth($t);
            });
            $h = max($h, $pdf->getStringHeight($c['w'], $row[$i]));
        }
        if ($y + $h > PDF_LIMIT) {
            $pdf->Line(PDF_L, $y, PDF_L + PDF_W, $y);
            $pdf->AddPage();
            $y = pdf__continued($pdf, $contLabel);
            $y = $drawHeader($y);
            $pdf->SetFont('helvetica', '', 9.5);
            $pdf->setCellPaddings(1.6, 1.4, 1.6, 1.0);
        }
        if ($n % 2 === 1) {
            $pdf->SetFillColor(230, 230, 230);
            $pdf->Rect(PDF_L, $y, PDF_W, $h, 'F');
        }
        foreach ($cols as $i => $c) {
            $pdf->MultiCell($c['w'], $h, (string)($row[$i] ?? ''), 0, $c['align'], false, 0, $edges[$i], $y, true, 0, false, true, $h, 'T');
        }
        $verticals($y, $y + $h);
        $y += $h;
    }
    $pdf->setCellPaddings(0, 0, 0, 0);

    // Minimum height (first page only): run the column rules on down, never past the room left on the page.
    $target = $pdf->getPage() === $startPage ? min($minBottom, $maxBottom, PDF_LIMIT) : 0.0;
    if ($target > $y) {
        $verticals($y, $target);
        $y = $target;
    }
    $pdf->Line(PDF_L, $y, PDF_L + PDF_W, $y);
    return ['bottom' => $y, 'edges' => $edges];
}

function pdf__continued(TCPDF $pdf, string $label): float
{
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->MultiCell(PDF_W, 5, $label . ' (continued)', 0, 'L', false, 1, PDF_L, PDF_TOP);
    $pdf->SetTextColor(0, 0, 0);
    return PDF_TOP + 7.0;
}

/**
 * Everything under the table. $c keys: totals [[label, value]], notes, line (bold sentence above thank-you),
 * thank_you, details (payment details text or ''), remittance ([[label, value]] or []).
 */
function pdf__closing_height(TCPDF $pdf, array $c): float
{
    $h = 3.0 + count($c['totals']) * PDF_TP + 3.0;
    if (trim((string)$c['notes']) !== '') {
        $pdf->SetFont('helvetica', '', 9);
        $h += $pdf->getStringHeight(PDF_W, (string)$c['notes']) + 3.0;
    }
    if ($c['line'] !== '') {
        $h += 6.0;
    }
    $h += 6.0;
    $h += count(pdf__detail_lines((string)$c['details'])) * PDF_DP + 2.0;
    if ($c['remittance']) {
        $h += 2.5 + 3.0 + 2.5 + 6.0 + count($c['remittance']) * PDF_RP + 4.0;
    } else {
        $h += 3.0;
    }
    return $h + 1.0;
}

function pdf__detail_lines(string $details): array
{
    $lines = preg_split('/\r\n|\r|\n/', $details);
    $lines = array_map('rtrim', $lines);
    while ($lines && $lines[0] === '') {
        array_shift($lines);
    }
    while ($lines && end($lines) === '') {
        array_pop($lines);
    }
    return $lines;
}

function pdf__closing(TCPDF $pdf, array $company, array $edges, float $y, array $c): void
{
    $red = pdf__red($company);
    $need = pdf__closing_height($pdf, $c);
    if ($y + $need > PDF_LIMIT) {
        $pdf->AddPage();
        $y = PDF_TOP;
    }

    // Totals: label right-aligned to the second-last column's edge, value in the last column.
    $n = count($edges);
    $valX = min($edges[$n - 2], PDF_L + PDF_W - 30.3);
    $valW = $edges[$n - 1] - $valX - 1.6;
    $labW = $valX - PDF_L - 1.6;
    $y += 3.0;
    $pdf->SetFont('helvetica', 'B', 9.5);
    foreach ($c['totals'] as $t) {
        pdf__line($pdf, PDF_L, $y, $labW, (string)$t[0], 'R', PDF_TP);
        pdf__line($pdf, $valX, $y, $valW, (string)$t[1], 'R', PDF_TP);
        $y += PDF_TP;
    }
    $y += 3.0;

    if (trim((string)$c['notes']) !== '') {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->MultiCell(PDF_W, 0, (string)$c['notes'], 0, 'L', false, 1, PDF_L, $y);
        $y = $pdf->GetY() + 3.0;
    }
    if ($c['line'] !== '') {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->MultiCell(PDF_W, 5, (string)$c['line'], 0, 'L', false, 1, PDF_L, $y);
        $y += 6.0;
    }
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->MultiCell(PDF_W, 5, (string)$c['thank_you'], 0, 'L', false, 1, PDF_L, $y);
    $y += 6.0;

    $pdf->SetFont('helvetica', 'B', 9.5);
    foreach (pdf__detail_lines((string)$c['details']) as $line) {
        if ($line !== '') {
            pdf__line($pdf, PDF_L, $y, PDF_W, $line);
        }
        $y += PDF_DP;
    }
    $y += 2.0;

    $pdf->SetFillColor($red[0], $red[1], $red[2]);
    if ($c['remittance']) {
        $y += 3.0;
        $pdf->Rect(PDF_L, $y, PDF_W, 3.0, 'F');
        $y += 3.0 + 2.5;
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->MultiCell(PDF_W, 5, 'REMITTANCE', 0, 'C', false, 1, PDF_L, $y);
        $y += 6.0;
        $pdf->SetFont('helvetica', '', 10.5);
        foreach ($c['remittance'] as $r) {
            pdf__line($pdf, PDF_L + 1.8, $y, 38, (string)$r[0], 'L', PDF_RP);
            pdf__line($pdf, PDF_L + 40.0, $y, PDF_W - 42, (string)$r[1], 'L', PDF_RP);
            $y += PDF_RP;
        }
        $y += 4.0;
    } else {
        $y += 3.0;
    }
    $pdf->Rect(PDF_L, $y, PDF_W, 0.8, 'F');
}

function pdf__finish(TCPDF $pdf): string
{
    $pages = $pdf->getNumPages();
    if ($pages > 1) {
        for ($i = 1; $i <= $pages; $i++) {
            $pdf->setPage($i);
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(110, 110, 110);
            $pdf->MultiCell(PDF_W, 4, 'Page ' . $i . ' of ' . $pages, 0, 'R', false, 1, PDF_L, 289.5);
        }
        $pdf->lastPage();
    }
    return $pdf->Output('document.pdf', 'S');
}

function pdf__customer(array $doc): array
{
    $t = is_array($doc['tenant'] ?? null) ? $doc['tenant'] : [];
    $contact = trim((string)($doc['customer_contact'] ?? ''));
    $name = trim((string)($doc['customer_name'] ?? ''));
    $address = (string)($doc['customer_address'] ?? '');
    if ($contact === '' && $name === '') {
        $contact = trim((string)($t['contact_name'] ?? ''));
        $name = trim((string)($t['name'] ?? ''));
    }
    if (trim($address) === '') {
        $address = (string)($t['billing_address'] ?? '');
    }
    $lines = [];
    if ($contact !== '') {
        $lines[] = $contact;
    }
    if ($name !== '' && strcasecmp($name, $contact) !== 0) {
        $lines[] = $name;
    }
    return array_merge($lines, pdf_lines($address));
}

// ---------------------------------------------------------------- documents

function pdf_document(array $doc, array $company): string
{
    $isQuote = ($doc['kind'] ?? 'invoice') === 'quote';
    $title = $isQuote ? 'Quotation' : 'Invoice';
    $cur = (string)($doc['currency'] ?? 'MWK');
    $number = (string)($doc['number'] ?? '');
    $total = round((float)($doc['total'] ?? 0), 2);
    $paid = round((float)($doc['amount_paid'] ?? 0), 2);
    $balance = isset($doc['balance']) ? round((float)$doc['balance'], 2) : round($total - $paid, 2);
    $subtotal = round((float)($doc['subtotal'] ?? 0), 2);
    $discount = round((float)($doc['discount'] ?? 0), 2);
    $tax = round((float)($doc['tax'] ?? 0), 2);
    $taxRate = round((float)($doc['tax_rate'] ?? 0), 2);
    $status = (string)($doc['status'] ?? '');

    // No DRAFT stamp: the first emailed copy is built while the row is still a draft.
    $stamp = '';
    if (!$isQuote && $status === 'void') {
        $stamp = 'VOID';
    } elseif (!$isQuote && $status === 'paid') {
        $stamp = 'PAID';
    }

    $pdf = pdf__new($title, $number, $company);
    $meta = [
        ['Date:', pdf_date($doc['issue_date'] ?? null)],
        [$isQuote ? 'Quotation No.:' : 'Invoice No.:', $number],
        [$isQuote ? 'Valid Until:' : 'Due Date:', pdf_date($doc['due_date'] ?? null)],
    ];
    $sales = trim((string)($doc['salesperson'] ?? '')) !== '' ? (string)$doc['salesperson'] : (string)($company['salesperson'] ?? '');
    if (trim($sales) !== '') {
        $meta[] = ['Salesperson:', $sales];
    }
    $customer = pdf__customer($doc);
    $y = pdf__top($pdf, $title, $company, $meta, $customer, $stamp);

    $cols = [
        ['label' => 'Qty', 'w' => 12.0, 'align' => 'R'],
        ['label' => 'Item', 'w' => 28.2, 'align' => 'L'],
        ['label' => 'Description', 'w' => 68.5, 'align' => 'L'],
        ['label' => 'Unit Price', 'w' => 34.5, 'align' => 'R'],   // wide enough for MWK99,999,999.99 on one line
        ['label' => 'Total', 'w' => 34.8, 'align' => 'R'],
    ];
    $rows = [];
    foreach ((array)($doc['items'] ?? []) as $it) {
        $rows[] = [
            pdf_qty($it['qty'] ?? 0),
            (string)($it['item'] ?? ''),
            (string)($it['description'] ?? ''),
            pdf_money($it['unit_price'] ?? 0, $cur),
            pdf_money($it['line_total'] ?? 0, $cur),
        ];
    }

    $totals = [];
    if (abs($discount) > PDF_TOL || abs($tax) > PDF_TOL) {
        $totals[] = ['Subtotal', pdf_money($subtotal, $cur)];
    }
    if (abs($discount) > PDF_TOL) {
        $totals[] = ['Discount', pdf_money(-$discount, $cur)];
    }
    if (abs($tax) > PDF_TOL) {
        $label = trim((string)($company['tax_label'] ?? '')) !== '' ? (string)$company['tax_label'] : 'VAT';
        $totals[] = [$label . ' (' . rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.') . '%)', pdf_money($tax, $cur)];
    }
    $totals[] = ['Total', pdf_money($total, $cur)];
    if (!$isQuote) {
        if ($paid > PDF_TOL) {
            $totals[] = ['Amount Paid', pdf_money($paid, $cur)];
        }
        $totals[] = ['Balance Due', pdf_money($balance, $cur)];
    }

    $contact = trim((string)($doc['customer_contact'] ?? ''));
    $remitName = $contact !== '' ? $contact : (string)($customer[0] ?? '');
    $closing = [
        'totals' => $totals,
        'notes' => (string)($doc['notes'] ?? ''),
        'line' => $isQuote && pdf_date($doc['due_date'] ?? null) !== ''
            ? 'This quotation is valid until ' . pdf_date($doc['due_date']) . '.' : '',
        'thank_you' => (string)($company['thank_you'] ?? 'Thank you for your business.'),
        'details' => (string)($company['payment_details'] ?? ''),
        'remittance' => ($isQuote || $balance <= PDF_TOL) ? [] : [ // nothing to send back once it is paid
            ['Invoice No.:', $number],
            ['Customer Name:', $remitName],
            ['Date:', pdf_date($doc['issue_date'] ?? null)],
            ['Amount Enclosed:', pdf_money($balance, $cur)],
        ],
    ];
    pdf__body($pdf, $company, $cols, $rows, $y, $closing, $title . ' ' . $number);
    return pdf__finish($pdf);
}

/** Table + closing, with the table's minimum height capped so the closing still fits on the page. */
function pdf__body(TCPDF $pdf, array $company, array $cols, array $rows, float $y, array $closing, string $contLabel): void
{
    $need = pdf__closing_height($pdf, $closing);
    $minBottom = $y + 7.4 + 55.0;
    $res = pdf__table($pdf, $company, $cols, $rows, $y, $minBottom, PDF_LIMIT - $need, $contLabel);
    pdf__closing($pdf, $company, $res['edges'], $res['bottom'], $closing);
}

function pdf_statement(array $statement, array $company): string
{
    $t = is_array($statement['tenant'] ?? null) ? $statement['tenant'] : [];
    $cur = (string)($statement['currency'] ?? ($t['currency'] ?? 'MWK'));
    $from = (string)($statement['from'] ?? '');
    $to = (string)($statement['to'] ?? '');
    $toDisp = $to !== '' ? pdf_date($to) : date('d/m/Y');

    $pdf = pdf__new('Statement', (string)($t['name'] ?? ''), $company);
    $meta = [['Date:', $toDisp]];
    if ($from !== '') {
        $meta[] = ['From:', pdf_date($from)];
        $meta[] = ['To:', $toDisp];
    }
    $meta[] = ['Currency:', strtoupper($cur)];
    $customer = [];
    if (trim((string)($t['contact_name'] ?? '')) !== '') {
        $customer[] = trim((string)$t['contact_name']);
    }
    if (trim((string)($t['name'] ?? '')) !== '') {
        $customer[] = trim((string)$t['name']);
    }
    $customer = array_merge($customer, pdf_lines($t['billing_address'] ?? ''));
    $y = pdf__top($pdf, 'Statement', $company, $meta, $customer);

    $cols = [
        ['label' => 'Date', 'w' => 20.0, 'align' => 'L'],
        ['label' => 'Type', 'w' => 18.0, 'align' => 'L'],
        ['label' => 'Ref', 'w' => 22.0, 'align' => 'L'],
        ['label' => 'Description', 'w' => 46.0, 'align' => 'L'],
        ['label' => 'Debit', 'w' => 24.0, 'align' => 'R'],
        ['label' => 'Credit', 'w' => 24.0, 'align' => 'R'],
        ['label' => 'Balance', 'w' => 24.0, 'align' => 'R'],
    ];
    $rows = [];
    $opening = round((float)($statement['opening'] ?? 0), 2);
    if ($from !== '' || abs($opening) > PDF_TOL) {
        $rows[] = [pdf_date($from), '', '', 'Opening balance', '', '', pdf_number($opening)];
    }
    foreach ((array)($statement['lines'] ?? []) as $l) {
        $debit = round((float)($l['debit'] ?? 0), 2);
        $credit = round((float)($l['credit'] ?? 0), 2);
        $rows[] = [
            pdf_date($l['date'] ?? null),
            ucfirst((string)($l['type'] ?? '')),
            (string)($l['ref'] ?? ''),
            (string)($l['description'] ?? ''),
            abs($debit) > PDF_TOL ? pdf_number($debit) : '',
            abs($credit) > PDF_TOL ? pdf_number($credit) : '',
            pdf_number($l['balance'] ?? 0),
        ];
    }
    $closing = [
        'totals' => [['Amount Owed', pdf_money($statement['closing'] ?? 0, $cur)]],
        'notes' => '',
        'line' => '',
        'thank_you' => (string)($company['thank_you'] ?? 'Thank you for your business.'),
        'details' => (string)($company['payment_details'] ?? ''),
        'remittance' => [],
    ];
    pdf__body($pdf, $company, $cols, $rows, $y, $closing, 'Statement ' . (string)($t['name'] ?? ''));
    return pdf__finish($pdf);
}

function pdf_receipt(array $payment, array $doc, array $company): string
{
    $cur = (string)($payment['currency'] ?? ($doc['currency'] ?? 'MWK'));
    $docNo = (string)($doc['number'] ?? '');
    // Same number the mailer puts in the file name: "<invoice no>-<payment id>".
    $receiptNo = isset($payment['receipt_no']) ? (string)$payment['receipt_no']
        : trim($docNo . '-' . (string)($payment['id'] ?? ''), '-');
    $total = round((float)($doc['total'] ?? 0), 2);
    $paid = round((float)($doc['amount_paid'] ?? 0), 2);
    $balance = isset($doc['balance']) ? round((float)$doc['balance'], 2) : round($total - $paid, 2);
    // A reprint for an earlier part payment shows the invoice as it stood right after THAT payment, not today.
    if (isset($payment['paid_to_date'])) {
        $paid = round((float)$payment['paid_to_date'], 2);
        $balance = round($total - $paid, 2);
    }
    $amount = round((float)($payment['amount'] ?? 0), 2);
    $date = pdf_date($payment['created_at'] ?? null);
    $onInvoice = $docNo !== ''; // a payment not against an invoice (licence payment) has no invoice lines on its receipt

    $pdf = pdf__new('Receipt', $receiptNo, $company);
    $meta = [
        ['Date:', $date],
        ['Receipt No.:', $receiptNo],
    ];
    if ($onInvoice) {
        $meta[] = ['Invoice No.:', $docNo];
    }
    $sales = trim((string)($doc['salesperson'] ?? '')) !== '' ? (string)$doc['salesperson'] : (string)($company['salesperson'] ?? '');
    if (trim($sales) !== '') {
        $meta[] = ['Salesperson:', $sales];
    }
    $y = pdf__top($pdf, 'Receipt', $company, $meta, pdf__customer($doc), $onInvoice && $balance <= PDF_TOL ? 'PAID' : '');

    $cols = [
        ['label' => 'Date', 'w' => 24.0, 'align' => 'L'],
        ['label' => 'Invoice No.', 'w' => 28.0, 'align' => 'L'],
        ['label' => 'Method', 'w' => 32.0, 'align' => 'L'],
        ['label' => 'Reference', 'w' => 63.7, 'align' => 'L'],
        ['label' => 'Amount', 'w' => 30.3, 'align' => 'R'],
    ];
    $method = trim((string)($payment['method'] ?? ''));
    $methodLabel = trim((string)($payment['method_label'] ?? '')) !== '' ? trim((string)$payment['method_label'])
        : ($method !== '' ? ucfirst(str_replace('_', ' ', $method)) : '');
    $rows = [[$date, $docNo, $methodLabel, (string)($payment['reference'] ?? ''), pdf_money($amount, $cur)]];

    $totals = [['Amount Received', pdf_money($amount, $cur)]];
    if ($onInvoice) {
        $totals[] = ['Invoice Total', pdf_money($total, $cur)];
        $totals[] = ['Total Paid', pdf_money($paid, $cur)];
        $totals[] = ['Balance Left', pdf_money($balance, $cur)];
    }
    $closing = [
        'totals' => $totals,
        'notes' => (string)($payment['description'] ?? ''),
        'line' => $onInvoice && $balance <= PDF_TOL ? 'Invoice ' . $docNo . ' is paid in full.' : '',
        'thank_you' => 'Thank you for your payment.',
        'details' => $onInvoice && $balance > PDF_TOL ? (string)($company['payment_details'] ?? '') : '',
        'remittance' => [],
    ];
    pdf__body($pdf, $company, $cols, $rows, $y, $closing, 'Receipt ' . $receiptNo);
    return pdf__finish($pdf);
}
