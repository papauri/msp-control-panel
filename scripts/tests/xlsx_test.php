<?php
// Pure-PHP XLSX writer (app/xlsx.php): builds a 2-sheet workbook, then opens it with an independent zip reader written
// here (end record -> central directory -> local headers), checks every CRC and size, inflates every part, checks the
// XML is well-formed and holds what Excel needs. Writes a sample to storage/tmp/sample-export.xlsx.
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/xlsx.php';

// --- helpers ---------------------------------------------------------------------------------------------------
t_eq('col 1', 'A', xlsx_col(1));
t_eq('col 26', 'Z', xlsx_col(26));
t_eq('col 27', 'AA', xlsx_col(27));
t_eq('col 702', 'ZZ', xlsx_col(702));
t_eq('col 703', 'AAA', xlsx_col(703));
t_eq('serial 1900-03-01', 61.0, xlsx_date_serial('1900-03-01'));
t_eq('serial 2026-01-01', 46023.0, xlsx_date_serial('2026-01-01'));
t_eq('serial 2026-01-17', 46039.0, xlsx_date_serial('2026-01-17'));
t_eq('serial with time', 46335.604167, xlsx_date_serial('2026-11-09 14:30:00'));
t_eq('serial invalid date', null, xlsx_date_serial('2026-02-30'));
t_eq('serial not a date', null, xlsx_date_serial('soon'));
t_eq('serial null', null, xlsx_date_serial(null));
date_default_timezone_set('Africa/Blantyre');
t_eq('serial ignores server timezone', 46039.0, xlsx_date_serial('2026-01-17'));
date_default_timezone_set('UTC');
t_eq('xml escape', '&lt;b&gt; &amp; &quot;x&quot; &apos;y&apos;', xlsx_xml('<b> & "x" \'y\''));
t_eq('xml strips control chars', 'ab' . "\t" . 'c', xlsx_xml("a\x00b\x07\t\x1Fc"));
t_eq('xml invalid utf-8 replaced', "a\u{FFFD}b", xlsx_xml("a\xC3b"));
t_eq('num plain', '1234567.89', xlsx_num(1234567.89));
t_eq('num integer', '602000', xlsx_num(602000.0));
t_eq('num small', '0.000001', xlsx_num(0.000001));
t_eq('num negative', '-12.5', xlsx_num(-12.5));
$names = xlsx_sheet_names(['Invoices', 'invoices', "A/B:C*D?[E]\\", "'quoted'", '', str_repeat('x', 40), str_repeat('x', 40)]);
t_eq('sheet name kept', 'Invoices', $names[0]);
t_eq('sheet name unique ignoring case', 'invoices (2)', $names[1]);
t_eq('sheet name bad chars', 'A B C D E', $names[2]);
t_eq('sheet name apostrophes trimmed', 'quoted', $names[3]);
t_eq('sheet name empty', 'Sheet5', $names[4]);
t_eq('sheet name 31 chars', 31, strlen($names[5]));
t_eq('sheet name long duplicate', str_repeat('x', 27) . ' (2)', $names[6]);
t_eq('filename ascii', 'Sales report 2026-11-01 to 2026-11-30.xlsx', xlsx_filename('Sales report 2026-11-01 to 2026-11-30'));
t_eq('filename unsafe chars', 'a-b-.xlsx', xlsx_filename("a\"b\r\n.xlsx"));

// --- a 2-sheet workbook ------------------------------------------------------------------------------------------
$sheets = [
    ['name' => 'Invoices', 'columns' => [
        ['title' => 'Invoice no.', 'type' => 'string', 'width' => 12],
        ['title' => 'Date', 'type' => 'date', 'width' => 12],
        ['title' => 'Client', 'type' => 'string', 'width' => 30],
        ['title' => 'Currency', 'type' => 'string', 'width' => 9],
        ['title' => 'Total', 'type' => 'money', 'width' => 15],
        ['title' => 'Qty', 'type' => 'number'],
    ], 'rows' => [
        ['170134', '2026-01-17', 'Liwonde Sun Hotel', 'MWK', '602000.00', 3],
        ['170135', '2026-11-09 14:30:00', "Tom & Jerry's <Shop>", 'EUR', 1234.567, '1.5'],
        ['170136', 'not a date', "  =SUM(A1:A2)", 'MWK', null, ''],
        ['170137', '', "Bad\x01chars\x00here \xC3 and ünïcødé ✓", 'USD', 'n/a', NAN],
    ]],
    ['name' => 'Payments', 'columns' => [
        ['title' => 'Date', 'type' => 'date'],
        ['title' => 'Amount', 'type' => 'money'],
        ['title' => 'Reference', 'type' => 'string', 'width' => 300],
    ], 'rows' => [
        ['2026-11-01', 100, 'Bank transfer'],
        ['2026-11-02', 0.1 + 0.2, str_repeat('long ', 10000)],
    ]],
];
$bytes = xlsx_build($sheets);
t_ok('starts with a local header', substr($bytes, 0, 4) === "PK\x03\x04");

// --- independent zip reader -----------------------------------------------------------------------------------------
function zt_read(string $z): array
{
    $eocd = strrpos($z, "PK\x05\x06");
    if ($eocd === false) {
        return ['error' => 'no end record'];
    }
    $e = unpack('Vsig/vdisk/vcddisk/ventries/vtotal/Vcdsize/Vcdoff/vclen', substr($z, $eocd, 22));
    $out = ['eocd' => $eocd, 'end' => $e, 'entries' => [], 'error' => ''];
    if ($e['cdoff'] + $e['cdsize'] !== $eocd) {
        $out['error'] = 'central directory does not end at the end record';
    }
    $p = $e['cdoff'];
    for ($i = 0; $i < $e['total']; $i++) {
        $c = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/vdisk/vint/Vext/Voff', substr($z, $p, 46));
        if ($c['sig'] !== 0x02014b50) {
            $out['error'] = "bad central header $i";
            return $out;
        }
        $name = substr($z, $p + 46, $c['nlen']);
        $p += 46 + $c['nlen'] + $c['xlen'] + $c['clen'];
        $l = unpack('Vsig/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen', substr($z, $c['off'], 30));
        $lname = substr($z, $c['off'] + 30, $l['nlen']);
        $data = substr($z, $c['off'] + 30 + $l['nlen'] + $l['xlen'], $c['csize']);
        $raw = $c['method'] === 8 ? @gzinflate($data) : ($c['method'] === 0 ? $data : false);
        $out['entries'][$name] = [
            'central' => $c, 'local' => $l, 'local_ok' => $l['sig'] === 0x04034b50 && $lname === $name
                && $l['crc'] === $c['crc'] && $l['csize'] === $c['csize'] && $l['usize'] === $c['usize'] && $l['method'] === $c['method']
                && ($l['flags'] & 8) === 0,
            'raw' => $raw,
        ];
    }
    if ($p !== $eocd) {
        $out['error'] = 'central directory size mismatch';
    }
    return $out;
}

function zt_xml(string $xml): ?DOMDocument
{
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok = $doc->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $ok ? $doc : null;
}

$zip = zt_read($bytes);
t_eq('zip: no structural error', '', $zip['error']);
t_eq('zip: entry count matches end record', $zip['end']['total'] ?? -1, count($zip['entries']));
t_eq('zip: end record entries this disk', $zip['end']['total'] ?? -1, $zip['end']['entries'] ?? -2);
t_eq('zip: first entry is [Content_Types].xml', '[Content_Types].xml', array_key_first($zip['entries']));
$need = ['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml',
    'xl/sharedStrings.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', 'docProps/core.xml', 'docProps/app.xml'];
foreach ($need as $n) {
    t_ok("zip: has $n", isset($zip['entries'][$n]));
}
$xml = [];
foreach ($zip['entries'] as $name => $en) {
    t_ok("$name: local header matches central", $en['local_ok']);
    t_ok("$name: deflated (method 8)", $en['central']['method'] === 8);
    t_ok("$name: inflates", is_string($en['raw']));
    if (!is_string($en['raw'])) {
        continue;
    }
    t_eq("$name: size matches", $en['central']['usize'], strlen($en['raw']));
    t_eq("$name: CRC32 matches", $en['central']['crc'], crc32($en['raw']));
    $doc = zt_xml($en['raw']);
    t_ok("$name: well-formed XML", $doc !== null);
    t_ok("$name: simplexml reads it", @simplexml_load_string($en['raw']) !== false);
    $xml[$name] = $doc;
}

// --- content checks -----------------------------------------------------------------------------------------------
$ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
$ct = $zip['entries']['[Content_Types].xml']['raw'];
t_ok('content types: workbook override', strpos($ct, 'PartName="/xl/workbook.xml"') !== false);
t_ok('content types: sheet2 override', strpos($ct, 'PartName="/xl/worksheets/sheet2.xml"') !== false);
$wb = $xml['xl/workbook.xml'];
$sheetEls = $wb->getElementsByTagNameNS($ns, 'sheet');
t_eq('workbook: 2 sheets', 2, $sheetEls->length);
t_eq('workbook: sheet names', ['Invoices', 'Payments'], [$sheetEls->item(0)->getAttribute('name'), $sheetEls->item(1)->getAttribute('name')]);
$rels = $zip['entries']['xl/_rels/workbook.xml.rels']['raw'];
t_ok('workbook rels: styles + shared strings', strpos($rels, 'Target="styles.xml"') !== false && strpos($rels, 'Target="sharedStrings.xml"') !== false);

$st = $xml['xl/styles.xml'];
$fmts = [];
foreach ($st->getElementsByTagNameNS($ns, 'numFmt') as $f) {
    $fmts[$f->getAttribute('numFmtId')] = $f->getAttribute('formatCode');
}
t_eq('styles: money format', '#,##0.00', $fmts['164'] ?? null);
t_eq('styles: date format', 'dd/mm/yyyy', $fmts['165'] ?? null);
$order = [];
foreach ($st->documentElement->childNodes as $c) {
    if ($c instanceof DOMElement) {
        $order[] = $c->localName;
    }
}
t_eq('styles: element order', ['numFmts', 'fonts', 'fills', 'borders', 'cellStyleXfs', 'cellXfs', 'cellStyles'], $order);
$fills = $st->getElementsByTagNameNS($ns, 'patternFill');
t_eq('styles: first fills none + gray125', ['none', 'gray125'], [$fills->item(0)->getAttribute('patternType'), $fills->item(1)->getAttribute('patternType')]);
$xfs = $st->getElementsByTagNameNS($ns, 'cellXfs')->item(0)->getElementsByTagNameNS($ns, 'xf');
t_eq('styles: header style uses the bold font', '1', $xfs->item(XLSX_STYLE_HEADER)->getAttribute('fontId'));
t_ok('styles: bold font', $st->getElementsByTagNameNS($ns, 'font')->item(1)->getElementsByTagNameNS($ns, 'b')->length === 1);
t_eq('styles: money style', '164', $xfs->item(XLSX_STYLE_MONEY)->getAttribute('numFmtId'));
t_eq('styles: date style', '165', $xfs->item(XLSX_STYLE_DATE)->getAttribute('numFmtId'));

$strings = [];
foreach ($xml['xl/sharedStrings.xml']->getElementsByTagNameNS($ns, 'si') as $si) {
    $strings[] = $si->textContent;
}
$ws = $xml['xl/worksheets/sheet1.xml'];
$order = [];
foreach ($ws->documentElement->childNodes as $c) {
    if ($c instanceof DOMElement) {
        $order[] = $c->localName;
    }
}
t_eq('sheet1: element order Excel requires', ['dimension', 'sheetViews', 'sheetFormatPr', 'cols', 'sheetData', 'pageMargins'], $order);
$pane = $ws->getElementsByTagNameNS($ns, 'pane')->item(0);
t_ok('sheet1: header row frozen', $pane && $pane->getAttribute('state') === 'frozen' && $pane->getAttribute('ySplit') === '1' && $pane->getAttribute('topLeftCell') === 'A2');
$cols = $ws->getElementsByTagNameNS($ns, 'col');
t_eq('sheet1: 6 column widths', 6, $cols->length);
t_eq('sheet1: width of Client', '30', $cols->item(2)->getAttribute('width'));
t_eq('sheet1: default number width', '10', $cols->item(5)->getAttribute('width'));
t_eq('sheet1: dimension', 'A1:F5', $ws->getElementsByTagNameNS($ns, 'dimension')->item(0)->getAttribute('ref'));
$cells = [];
foreach ($ws->getElementsByTagNameNS($ns, 'c') as $c) {
    $v = $c->getElementsByTagNameNS($ns, 'v')->item(0);
    $val = $v ? $v->textContent : null;
    if ($c->getAttribute('t') === 's') {
        $val = $strings[(int)$val] ?? '?';
    }
    $cells[$c->getAttribute('r')] = ['s' => $c->getAttribute('s'), 't' => $c->getAttribute('t'), 'v' => $val];
}
t_eq('A1 header text', 'Invoice no.', $cells['A1']['v']);
t_eq('A1 header bold style', (string)XLSX_STYLE_HEADER, $cells['A1']['s']);
t_eq('A2 numeric-looking text stays text', ['s' => '0', 't' => 's', 'v' => '170134'], $cells['A2']);
t_eq('B2 date serial', ['s' => (string)XLSX_STYLE_DATE, 't' => '', 'v' => '46039'], $cells['B2']);
t_eq('B3 datetime serial', '46335.604167', $cells['B3']['v']);
t_eq('B4 bad date kept as text', ['s' => '0', 't' => 's', 'v' => 'not a date'], $cells['B4']);
t_eq('E2 money from string', ['s' => (string)XLSX_STYLE_MONEY, 't' => '', 'v' => '602000'], $cells['E2']);
t_eq('E3 money rounded to 2 dp', '1234.57', $cells['E3']['v']);
t_ok('E4 null is no cell', !isset($cells['E4']));
t_eq('E5 non-numeric money kept as text', 'n/a', $cells['E5']['v']);
t_eq('F2 number', ['s' => (string)XLSX_STYLE_NUMBER, 't' => '', 'v' => '3'], $cells['F2']);
t_eq('F3 number from string', '1.5', $cells['F3']['v']);
t_ok('F4 empty string is no cell', !isset($cells['F4']));
t_ok('F5 NAN is no cell', !isset($cells['F5']));
t_eq('C3 escaped text round-trips', "Tom & Jerry's <Shop>", $cells['C3']['v']);
t_eq('C4 leading spaces and formula text kept as text', ['s' => '0', 't' => 's', 'v' => '  =SUM(A1:A2)'], $cells['C4']);
t_eq('C5 control chars stripped, bad byte replaced, unicode kept', "Badcharshere \u{FFFD} and ünïcødé ✓", $cells['C5']['v']);
t_ok('no formula cells anywhere', $ws->getElementsByTagNameNS($ns, 'f')->length === 0);

$ws2 = $xml['xl/worksheets/sheet2.xml'];
$c2 = [];
foreach ($ws2->getElementsByTagNameNS($ns, 'c') as $c) {
    $v = $c->getElementsByTagNameNS($ns, 'v')->item(0);
    $c2[$c->getAttribute('r')] = $c->getAttribute('t') === 's' ? ($strings[(int)$v->textContent] ?? '?') : $v->textContent;
}
t_eq('sheet2: 0.1 + 0.2 money', '0.3', $c2['B3']);
t_eq('sheet2: long text cut to Excel cell limit', XLSX_MAX_CELL_CHARS, strlen($c2['C3']));
t_eq('sheet2: width capped at 255', '255', $ws2->getElementsByTagNameNS($ns, 'col')->item(2)->getAttribute('width'));

// Empty workbook and a sheet with no rows still build valid zips.
$z = zt_read(xlsx_build([]));
t_eq('empty workbook: valid zip', '', $z['error']);
t_ok('empty workbook: one sheet', isset($z['entries']['xl/worksheets/sheet1.xml']) && zt_xml((string)$z['entries']['xl/worksheets/sheet1.xml']['raw']) !== null);

// Sample for opening in Excel / LibreOffice.
$dir = __DIR__ . '/../../storage/tmp';
if (!is_dir($dir)) {
    @mkdir($dir, 0700, true);
}
$path = $dir . '/sample-export.xlsx';
t_ok('sample written', file_put_contents($path, $bytes) === strlen($bytes));
echo 'Sample: ' . realpath($path) . ' (' . strlen($bytes) . " bytes)\n";
t_done('xlsx');
