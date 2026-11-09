<?php
// Pure-PHP Excel (.xlsx) writer (owner 2026-11-09, BILLING.md Part 2, agent REPORTS). No ZipArchive on the host, so the
// zip container is written here: stored deflate entries (gzdeflate level 6), CRC32 via crc32(), local headers + central
// directory. Bold header row, frozen; money as #,##0.00; dates as real Excel dates shown dd/mm/yyyy.
//
// xlsx_build([['name' => 'Invoices', 'columns' => [['title' => 'Date', 'type' => 'date', 'width' => 12], ...],
//              'rows' => [[...], ...]], ...]): string     types: string | number | money | date
// xlsx_stream($bytes, $filename): sends it as a download.

const XLSX_STYLE_TEXT = 0;
const XLSX_STYLE_HEADER = 1;
const XLSX_STYLE_MONEY = 2;
const XLSX_STYLE_DATE = 3;
const XLSX_STYLE_NUMBER = 4;
const XLSX_MAX_CELL_CHARS = 32767;
const XLSX_MAX_ROWS = 1048575; // data rows (row 1 is the header)

// Escaped and safe text for XML: invalid UTF-8 becomes U+FFFD, characters XML 1.0 forbids are removed.
function xlsx_xml(string $s): string
{
    $s = htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
    return $clean === null ? '' : $clean;
}

// 1 -> A, 27 -> AA.
function xlsx_col(int $n): string
{
    $s = '';
    while ($n > 0) {
        $m = ($n - 1) % 26;
        $s = chr(65 + $m) . $s;
        $n = intdiv($n - 1, 26);
    }
    return $s;
}

// "2026-01-17" or "2026-01-17 14:30:00" -> Excel serial number (1900 date system), computed in UTC. null when not a date.
function xlsx_date_serial($v): ?float
{
    if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', trim($v), $m)) {
        return null;
    }
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[1] < 1900) {
        return null;
    }
    $days = gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]) / 86400 + 25569;
    $secs = isset($m[4]) && $m[4] !== '' ? (int)$m[4] * 3600 + (int)$m[5] * 60 + (int)($m[6] ?? 0) : 0;
    return round($days + $secs / 86400, 6);
}

// Sheet names Excel accepts: 31 chars, none of []:*?/\, no apostrophe at either end, unique ignoring case.
function xlsx_sheet_names(array $names): array
{
    $out = [];
    $seen = [];
    foreach ($names as $i => $name) {
        $n = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', (string)$name), " '");
        $n = preg_replace('/\s+/u', ' ', $n) ?? '';
        if ($n === '') {
            $n = 'Sheet' . ($i + 1);
        }
        $n = function_exists('mb_substr') ? mb_substr($n, 0, 31, 'UTF-8') : substr($n, 0, 31);
        $base = $n;
        $k = 2;
        while (isset($seen[strtolower($n)])) {
            $suffix = ' (' . $k++ . ')';
            $n = (function_exists('mb_substr') ? mb_substr($base, 0, 31 - strlen($suffix), 'UTF-8') : substr($base, 0, 31 - strlen($suffix))) . $suffix;
        }
        $seen[strtolower($n)] = true;
        $out[] = $n;
    }
    return $out;
}

function xlsx_default_width(string $type): float
{
    return ['money' => 14.0, 'date' => 12.0, 'number' => 10.0][$type] ?? 20.0;
}

// One worksheet's XML. $strings is the shared-string table (value => index), filled as cells are written.
function xlsx_sheet_xml(array $sheet, array &$strings): string
{
    $columns = array_values((array)($sheet['columns'] ?? []));
    $rows = array_values((array)($sheet['rows'] ?? []));
    if (count($rows) > XLSX_MAX_ROWS) {
        $rows = array_slice($rows, 0, XLSX_MAX_ROWS);
    }
    $ncols = max(1, count($columns));
    $str = static function (string $v) use (&$strings): int {
        if (preg_match('/^.{0,' . XLSX_MAX_CELL_CHARS . '}/su', $v, $m) && strlen($m[0]) < strlen($v)) {
            $v = $m[0];
        }
        if (!isset($strings[$v])) {
            $strings[$v] = count($strings);
        }
        return $strings[$v];
    };

    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="A1:' . xlsx_col($ncols) . (count($rows) + 1) . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
        . '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>';
    if ($columns) {
        $x .= '<cols>';
        foreach ($columns as $i => $c) {
            $type = (string)($c['type'] ?? 'string');
            $w = isset($c['width']) && is_numeric($c['width']) && $c['width'] > 0 ? min(255.0, (float)$c['width']) : xlsx_default_width($type);
            $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . rtrim(rtrim(number_format($w, 2, '.', ''), '0'), '.') . '" customWidth="1"/>';
        }
        $x .= '</cols>';
    }
    $x .= '<sheetData><row r="1">';
    foreach ($columns as $i => $c) {
        $x .= '<c r="' . xlsx_col($i + 1) . '1" s="' . XLSX_STYLE_HEADER . '" t="s"><v>' . $str((string)($c['title'] ?? '')) . '</v></c>';
    }
    $x .= '</row>';
    foreach ($rows as $ri => $row) {
        $r = $ri + 2;
        $row = array_values((array)$row);
        $x .= '<row r="' . $r . '">';
        foreach ($row as $ci => $v) {
            $type = (string)($columns[$ci]['type'] ?? 'string');
            $ref = xlsx_col($ci + 1) . $r;
            if ($v === null || $v === '') {
                continue;
            }
            if ($type === 'date') {
                $serial = xlsx_date_serial($v);
                if ($serial !== null) {
                    $x .= '<c r="' . $ref . '" s="' . XLSX_STYLE_DATE . '"><v>' . xlsx_num($serial) . '</v></c>';
                    continue;
                }
            } elseif (($type === 'money' || $type === 'number') && (is_int($v) || is_float($v) || (is_string($v) && is_numeric(trim($v))))) {
                $f = (float)(is_string($v) ? trim($v) : $v);
                if (is_nan($f) || is_infinite($f)) {
                    continue;
                }
                if ($type === 'money') {
                    $f = round($f, 2);
                }
                $x .= '<c r="' . $ref . '" s="' . ($type === 'money' ? XLSX_STYLE_MONEY : XLSX_STYLE_NUMBER) . '"><v>' . xlsx_num($f) . '</v></c>';
                continue;
            }
            if (is_bool($v)) {
                $v = $v ? 'Yes' : 'No';
            }
            if (is_float($v) && (is_nan($v) || is_infinite($v))) {
                continue;
            }
            $x .= '<c r="' . $ref . '" s="' . XLSX_STYLE_TEXT . '" t="s"><v>' . $str((string)$v) . '</v></c>';
        }
        $x .= '</row>';
    }
    $x .= '</sheetData>'
        . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
        . '</worksheet>';
    return $x;
}

// Plain decimal text for a number (never exponent notation, never locale commas).
function xlsx_num(float $f): string
{
    if (abs($f - round($f)) < 1e-9 && abs($f) < 1e15) {
        return number_format($f, 0, '.', '');
    }
    $s = rtrim(rtrim(number_format($f, 6, '.', ''), '0'), '.');
    return $s === '-0' ? '0' : $s;
}

function xlsx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE6E6E6"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left/><right/><top/><bottom style="thin"><color rgb="FF808080"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

// The workbook parts (path => XML), in zip order ([Content_Types].xml first).
function xlsx_parts(array $sheets): array
{
    $sheets = array_values($sheets);
    if (!$sheets) {
        $sheets = [['name' => 'Sheet1', 'columns' => [], 'rows' => []]];
    }
    $names = xlsx_sheet_names(array_map(static fn($s): string => (string)($s['name'] ?? ''), $sheets));
    $strings = [];
    $sheetXml = [];
    foreach ($sheets as $i => $s) {
        $sheetXml[$i] = xlsx_sheet_xml((array)$s, $strings);
    }
    $n = count($sheets);

    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
    for ($i = 1; $i <= $n; $i++) {
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $ct .= '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:creator>Licence panel</dc:creator>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
        . '</cp:coreProperties>';
    $app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Licence panel</Application></Properties>';

    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<bookViews><workbookView/></bookViews><sheets>';
    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    for ($i = 1; $i <= $n; $i++) {
        $wb .= '<sheet name="' . xlsx_xml($names[$i - 1]) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $wbRels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
    }
    $wb .= '</sheets></workbook>';
    $wbRels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rId' . ($n + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '</Relationships>';

    $ss = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
    foreach (array_keys($strings) as $s) {
        $s = (string)$s;
        $ss .= '<si><t' . ($s !== trim($s) ? ' xml:space="preserve"' : '') . '>' . xlsx_xml($s) . '</t></si>';
    }
    $ss .= '</sst>';

    $parts = [
        '[Content_Types].xml' => $ct,
        '_rels/.rels' => $rels,
        'docProps/core.xml' => $core,
        'docProps/app.xml' => $app,
        'xl/workbook.xml' => $wb,
        'xl/_rels/workbook.xml.rels' => $wbRels,
        'xl/styles.xml' => xlsx_styles_xml(),
        'xl/sharedStrings.xml' => $ss,
    ];
    foreach ($sheetXml as $i => $x) {
        $parts['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $x;
    }
    return $parts;
}

/**
 * A zip archive of $files (name => bytes): each entry deflated (method 8), CRC32, sizes in the local header (no data
 * descriptor), then the central directory and the end record. $time sets the entries' modified time (default now).
 */
function xlsx_zip(array $files, ?int $time = null): string
{
    $t = getdate($time ?? time());
    $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    $dosDate = (max(0, $t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
    $out = '';
    $central = '';
    $count = 0;
    foreach ($files as $name => $data) {
        $name = (string)$name;
        $data = (string)$data;
        $crc = crc32($data);
        $deflated = gzdeflate($data, 6);
        if ($deflated === false) {
            throw new RuntimeException('xlsx: deflate failed');
        }
        $offset = strlen($out);
        $head = pack('vvvvvVVVvv', 20, 0x0800, 8, $dosTime, $dosDate, $crc, strlen($deflated), strlen($data), strlen($name), 0);
        $out .= pack('V', 0x04034b50) . $head . $name . $deflated;
        $central .= pack('V', 0x02014b50) . pack('v', 20) . $head . pack('vvvVV', 0, 0, 0, 0, $offset) . $name;
        $count++;
    }
    return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($out), 0);
}

function xlsx_build(array $sheets): string
{
    return xlsx_zip(xlsx_parts($sheets));
}

// ASCII-only download name ending in .xlsx.
function xlsx_filename(string $filename): string
{
    $f = preg_replace('/[^A-Za-z0-9 ._()-]+/', '-', $filename) ?? '';
    $f = trim(preg_replace('/\s+/', ' ', $f) ?? '', ' .-');
    if ($f === '') {
        $f = 'export';
    }
    if (strtolower(substr($f, -5)) !== '.xlsx') {
        $f .= '.xlsx';
    }
    return $f;
}

function xlsx_stream(string $bytes, string $filename): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . xlsx_filename($filename) . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
}
