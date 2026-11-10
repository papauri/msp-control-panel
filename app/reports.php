<?php
// Sales reports (owner 2026-11-09, BILLING.md Part 2, agent REPORTS). READ-ONLY: every query here is a SELECT.
// Money rules: invoices count only when sent or paid (draft and void never); collected = every payment recorded in the
// period: against an invoice, and licence payments recorded on a business page without one (2026-11-10, so Collected is
// all cash received; such a payment is only allowed while no licence invoice is open, so nothing counts twice);
// outstanding / overdue / aged debt = balances of sent invoices as of today;
// profit = sum of (line_total - qty x cost_price) over lines whose cost_price is known (NULL = unknown, 0.00 = known,
// zero cost), flagged "partial" when some lines have no cost. Different currencies are never added together: every
// total is grouped by currency. Client name: the document's client, else the client linked to its business, else the
// customer name written on the document.

const REPORTS_BUCKETS = ['current' => 'Current', '1_30' => '1-30 days', '31_60' => '31-60 days', '61_90' => '61-90 days', '90_plus' => 'Over 90 days'];
const REPORTS_PERIODS = ['this_month' => 'This month', 'last_month' => 'Last month', 'this_year' => 'This year', 'custom' => 'Custom dates'];

function reports_tol(): float
{
    return defined('BILLING_TOLERANCE') ? (float)BILLING_TOLERANCE : 0.005;
}

function reports_valid_date($s): bool
{
    return is_string($s) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) === 1 && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

function reports_dmy(string $ymd): string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m) ? $m[3] . '/' . $m[2] . '/' . $m[1] : '';
}

/**
 * Pure. The reporting period: ['key','from','to','label']. Custom dates must both be valid (swapped when reversed);
 * anything invalid falls back to this month.
 */
function reports_period(string $key, ?string $from, ?string $to, string $today): array
{
    $y = (int)substr($today, 0, 4);
    $mo = (int)substr($today, 5, 2);
    $monthEnd = static fn(int $yy, int $mm): string => sprintf('%04d-%02d-%02d', $yy, $mm, reports_month_days($yy, $mm));
    if ($key === 'custom' && reports_valid_date($from) && reports_valid_date($to)) {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
    } elseif ($key === 'last_month') {
        $ly = $mo === 1 ? $y - 1 : $y;
        $lm = $mo === 1 ? 12 : $mo - 1;
        $from = sprintf('%04d-%02d-01', $ly, $lm);
        $to = $monthEnd($ly, $lm);
    } elseif ($key === 'this_year') {
        $from = sprintf('%04d-01-01', $y);
        $to = sprintf('%04d-12-31', $y);
    } else {
        $key = 'this_month';
        $from = sprintf('%04d-%02d-01', $y, $mo);
        $to = $monthEnd($y, $mo);
    }
    return ['key' => $key, 'from' => $from, 'to' => $to,
        'label' => REPORTS_PERIODS[$key] . ' (' . reports_dmy($from) . ' to ' . reports_dmy($to) . ')'];
}

function reports_month_days(int $y, int $m): int
{
    return (int)gmdate('t', gmmktime(0, 0, 0, $m, 1, $y));
}

// Pure. Whole days past the due date (0 when not yet due, on the due date, or no due date).
function reports_days_overdue(?string $due, string $today): int
{
    if ($due === null || !reports_valid_date(substr($due, 0, 10))) {
        return 0;
    }
    $a = new DateTimeImmutable(substr($due, 0, 10), new DateTimeZone('UTC'));
    $b = new DateTimeImmutable(substr($today, 0, 10), new DateTimeZone('UTC'));
    return max(0, (int)$a->diff($b)->format('%r%a'));
}

// Pure. current (not overdue), 1_30, 31_60, 61_90, 90_plus.
function reports_age_bucket(?string $due, string $today): string
{
    $d = reports_days_overdue($due, $today);
    if ($d <= 0) {
        return 'current';
    }
    if ($d <= 30) {
        return '1_30';
    }
    if ($d <= 60) {
        return '31_60';
    }
    return $d <= 90 ? '61_90' : '90_plus';
}

// Pure. True when a document is an invoice that counts (sent or paid; draft and void never count).
function reports_counts(array $d): bool
{
    return ($d['kind'] ?? 'invoice') === 'invoice' && in_array((string)($d['status'] ?? ''), ['sent', 'paid'], true);
}

function reports_balance(array $d): float
{
    if (isset($d['balance']) && !isset($d['total'])) {
        return round((float)$d['balance'], 2);
    }
    return round((float)($d['total'] ?? 0) - (float)($d['amount_paid'] ?? 0), 2);
}

// Pure. Who a row belongs to: the client id when known, else the name (so one-off customers still group together).
function reports_client_key(array $r): string
{
    $id = (int)($r['client_ref'] ?? 0);
    return $id > 0 ? 'c' . $id : 'n:' . strtolower(trim((string)($r['client_name'] ?? '')));
}

function reports_cost_known(array $line): bool
{
    return array_key_exists('cost_price', $line) && $line['cost_price'] !== null && $line['cost_price'] !== '' && is_numeric($line['cost_price']);
}

// Pure. Sum of $field per currency: [currency => amount], currencies sorted.
function reports_sum_by_currency(array $rows, string $field, string $curField = 'currency'): array
{
    $out = [];
    foreach ($rows as $r) {
        $c = strtoupper((string)($r[$curField] ?? ''));
        $out[$c] = round(($out[$c] ?? 0.0) + (float)($r[$field] ?? 0), 2);
    }
    ksort($out);
    return $out;
}

/**
 * Pure. Profit per currency from invoice lines: ['revenue' (all lines), 'costed_revenue', 'cost', 'profit',
 * 'lines', 'lines_without_cost', 'partial' (some lines lack a cost), 'known' (at least one line has a cost)].
 */
function reports_profit(array $lines): array
{
    // The invoice's own discount (doc_discount, once per document_id) comes off revenue; off profit too when every line
    // of that invoice has a cost, exactly as billing_profit() does on the document page.
    $docs = [];
    foreach ($lines as $l) {
        $id = (int)($l['document_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $docs[$id] = $docs[$id] ?? ['currency' => strtoupper((string)($l['currency'] ?? '')), 'discount' => round((float)($l['doc_discount'] ?? 0), 2), 'unknown' => 0];
        if (!reports_cost_known($l)) {
            $docs[$id]['unknown']++;
        }
    }
    $out = [];
    foreach ($docs as $d) {
        if (abs($d['discount']) <= reports_tol()) {
            continue;
        }
        $c = $d['currency'];
        $p = $out[$c] ?? ['revenue' => 0.0, 'costed_revenue' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'lines' => 0, 'lines_without_cost' => 0];
        $p['revenue'] = round($p['revenue'] - $d['discount'], 2);
        if ($d['unknown'] === 0) {
            $p['costed_revenue'] = round($p['costed_revenue'] - $d['discount'], 2);
            $p['profit'] = round($p['profit'] - $d['discount'], 2);
        }
        $out[$c] = $p;
    }
    foreach ($lines as $l) {
        $c = strtoupper((string)($l['currency'] ?? ''));
        $p = $out[$c] ?? ['revenue' => 0.0, 'costed_revenue' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'lines' => 0, 'lines_without_cost' => 0];
        $lt = round((float)($l['line_total'] ?? 0), 2);
        $p['revenue'] = round($p['revenue'] + $lt, 2);
        $p['lines']++;
        if (reports_cost_known($l)) {
            $cost = round((float)($l['qty'] ?? 0) * (float)$l['cost_price'], 2);
            $p['costed_revenue'] = round($p['costed_revenue'] + $lt, 2);
            $p['cost'] = round($p['cost'] + $cost, 2);
            $p['profit'] = round($p['profit'] + $lt - $cost, 2);
        } else {
            $p['lines_without_cost']++;
        }
        $out[$c] = $p;
    }
    foreach ($out as $c => $p) {
        $out[$c]['partial'] = $p['lines_without_cost'] > 0;
        $out[$c]['known'] = $p['lines_without_cost'] < $p['lines'];
    }
    ksort($out);
    return $out;
}

/**
 * Pure. Aged debt per client and currency from open invoices (rows with client_ref, client_name, currency, due_date,
 * total + amount_paid or balance). Rows with no balance left are skipped. Sorted by currency, then largest total.
 */
function reports_aged_debt(array $open, string $today): array
{
    $tol = reports_tol();
    $rows = [];
    foreach ($open as $d) {
        $bal = reports_balance($d);
        if ($bal <= $tol) {
            continue;
        }
        $cur = strtoupper((string)($d['currency'] ?? ''));
        $k = $cur . '|' . reports_client_key($d);
        if (!isset($rows[$k])) {
            $rows[$k] = ['client_id' => (int)($d['client_ref'] ?? 0), 'client' => (string)($d['client_name'] ?? ''), 'currency' => $cur,
                'invoices' => 0, 'oldest_due' => null, 'total' => 0.0] + array_fill_keys(array_keys(REPORTS_BUCKETS), 0.0);
        }
        $b = reports_age_bucket($d['due_date'] ?? null, $today);
        $rows[$k][$b] = round($rows[$k][$b] + $bal, 2);
        $rows[$k]['total'] = round($rows[$k]['total'] + $bal, 2);
        $rows[$k]['invoices']++;
        $due = $d['due_date'] ?? null;
        if ($due !== null && $due !== '' && ($rows[$k]['oldest_due'] === null || $due < $rows[$k]['oldest_due'])) {
            $rows[$k]['oldest_due'] = (string)$due;
        }
    }
    $rows = array_values($rows);
    usort($rows, static function (array $a, array $b): int {
        return [$a['currency'], -$a['total'], strtolower($a['client'])] <=> [$b['currency'], -$b['total'], strtolower($b['client'])];
    });
    return $rows;
}

// Pure. Aged-debt totals per currency: [currency => [buckets..., 'total', 'clients']].
function reports_aged_totals(array $agedRows): array
{
    $out = [];
    foreach ($agedRows as $r) {
        $c = $r['currency'];
        $t = $out[$c] ?? (['total' => 0.0, 'clients' => 0] + array_fill_keys(array_keys(REPORTS_BUCKETS), 0.0));
        foreach (array_keys(REPORTS_BUCKETS) as $b) {
            $t[$b] = round($t[$b] + (float)$r[$b], 2);
        }
        $t['total'] = round($t['total'] + (float)$r['total'], 2);
        $t['clients']++;
        $out[$c] = $t;
    }
    ksort($out);
    return $out;
}

/**
 * Pure. Headline figures per currency: invoiced + invoices (counting invoices issued in the period), collected
 * (payments against invoices in the period), outstanding + overdue (open invoices today), profit / partial / known /
 * lines_without_cost (lines of the period's invoices).
 */
function reports_cards(array $invoices, array $payments, array $open, array $lines, string $today): array
{
    $tol = reports_tol();
    $blank = ['invoiced' => 0.0, 'invoices' => 0, 'collected' => 0.0, 'outstanding' => 0.0, 'overdue' => 0.0,
        'profit' => 0.0, 'partial' => false, 'known' => false, 'lines_without_cost' => 0];
    $out = [];
    foreach ($invoices as $d) {
        if (!reports_counts($d)) {
            continue;
        }
        $c = strtoupper((string)$d['currency']);
        $out[$c] = $out[$c] ?? $blank;
        $out[$c]['invoiced'] = round($out[$c]['invoiced'] + (float)$d['total'], 2);
        $out[$c]['invoices']++;
    }
    foreach ($payments as $p) {
        $c = strtoupper((string)$p['currency']);
        $out[$c] = $out[$c] ?? $blank;
        $out[$c]['collected'] = round($out[$c]['collected'] + (float)$p['amount'], 2);
    }
    foreach ($open as $d) {
        if (($d['status'] ?? 'sent') !== 'sent') {
            continue;
        }
        $bal = reports_balance($d);
        if ($bal <= $tol) {
            continue;
        }
        $c = strtoupper((string)$d['currency']);
        $out[$c] = $out[$c] ?? $blank;
        $out[$c]['outstanding'] = round($out[$c]['outstanding'] + $bal, 2);
        if (reports_days_overdue($d['due_date'] ?? null, $today) > 0) {
            $out[$c]['overdue'] = round($out[$c]['overdue'] + $bal, 2);
        }
    }
    foreach (reports_profit($lines) as $c => $p) {
        $out[$c] = $out[$c] ?? $blank;
        $out[$c]['profit'] = $p['profit'];
        $out[$c]['partial'] = $p['partial'];
        $out[$c]['known'] = $p['known'];
        $out[$c]['lines_without_cost'] = $p['lines_without_cost'];
    }
    ksort($out);
    return $out;
}

// Pure. Sales by month and currency: month (YYYY-MM), invoices, invoiced, collected, profit, partial, lines_without_cost.
function reports_by_month(array $invoices, array $payments, array $lines): array
{
    $rows = [];
    $row = static function (string $m, string $c) use (&$rows): string {
        $k = $m . '|' . $c;
        $rows[$k] = $rows[$k] ?? ['month' => $m, 'currency' => $c, 'invoices' => 0, 'invoiced' => 0.0, 'collected' => 0.0,
            'profit' => 0.0, 'partial' => false, 'known' => false, 'lines_without_cost' => 0];
        return $k;
    };
    foreach ($invoices as $d) {
        if (!reports_counts($d)) {
            continue;
        }
        $k = $row(substr((string)$d['issue_date'], 0, 7), strtoupper((string)$d['currency']));
        $rows[$k]['invoices']++;
        $rows[$k]['invoiced'] = round($rows[$k]['invoiced'] + (float)$d['total'], 2);
    }
    foreach ($payments as $p) {
        $k = $row(substr((string)$p['created_at'], 0, 7), strtoupper((string)$p['currency']));
        $rows[$k]['collected'] = round($rows[$k]['collected'] + (float)$p['amount'], 2);
    }
    $byMonth = [];
    foreach ($lines as $l) {
        $byMonth[substr((string)$l['issue_date'], 0, 7)][] = $l;
    }
    foreach ($byMonth as $m => $ls) {
        foreach (reports_profit($ls) as $c => $p) {
            $k = $row((string)$m, $c);
            $rows[$k]['profit'] = $p['profit'];
            $rows[$k]['partial'] = $p['partial'];
            $rows[$k]['known'] = $p['known'];
            $rows[$k]['lines_without_cost'] = $p['lines_without_cost'];
        }
    }
    ksort($rows);
    return array_values($rows);
}

// Pure. Sales by client and currency: invoices, invoiced, balance (left on those invoices), collected (in the period).
function reports_by_client(array $invoices, array $payments): array
{
    $rows = [];
    $row = static function (array $r, string $c) use (&$rows): string {
        $k = $c . '|' . reports_client_key($r);
        $rows[$k] = $rows[$k] ?? ['client_id' => (int)($r['client_ref'] ?? 0), 'client' => (string)($r['client_name'] ?? ''), 'currency' => $c,
            'invoices' => 0, 'invoiced' => 0.0, 'balance' => 0.0, 'collected' => 0.0];
        return $k;
    };
    foreach ($invoices as $d) {
        if (!reports_counts($d)) {
            continue;
        }
        $k = $row($d, strtoupper((string)$d['currency']));
        $rows[$k]['invoices']++;
        $rows[$k]['invoiced'] = round($rows[$k]['invoiced'] + (float)$d['total'], 2);
        if ($d['status'] === 'sent') {
            $rows[$k]['balance'] = round($rows[$k]['balance'] + max(0.0, reports_balance($d)), 2);
        }
    }
    foreach ($payments as $p) {
        $k = $row($p, strtoupper((string)$p['currency']));
        $rows[$k]['collected'] = round($rows[$k]['collected'] + (float)$p['amount'], 2);
    }
    $rows = array_values($rows);
    usort($rows, static function (array $a, array $b): int {
        return [$a['currency'], -$a['invoiced'], strtolower($a['client'])] <=> [$b['currency'], -$b['invoiced'], strtolower($b['client'])];
    });
    return $rows;
}

function reports_category_label(?string $cat): string
{
    $cat = trim((string)$cat);
    return $cat === '' ? 'Not a saved item' : ucfirst($cat);
}

// Pure. Sales by product (by=item) or category (by=category) per currency: qty, lines, revenue, cost, profit, partial.
function reports_by_product(array $lines, string $by = 'item'): array
{
    $rows = [];
    foreach ($lines as $l) {
        $c = strtoupper((string)($l['currency'] ?? ''));
        $cat = reports_category_label($l['category'] ?? null);
        if ($by === 'category') {
            $k = $c . '|' . $cat;
        } else {
            $k = $c . '|' . (!empty($l['catalog_item_id']) ? 'id' . (int)$l['catalog_item_id'] : 'n:' . strtolower(trim((string)$l['item'])));
        }
        $r = $rows[$k] ?? ['currency' => $c, 'category' => $cat, 'sku' => (string)($l['sku'] ?? ''), 'item' => (string)($l['item'] ?? ''),
            'qty' => 0.0, 'lines' => 0, 'revenue' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'lines_without_cost' => 0];
        $lt = round((float)($l['line_total'] ?? 0), 2);
        $r['qty'] = round($r['qty'] + (float)($l['qty'] ?? 0), 2);
        $r['lines']++;
        $r['revenue'] = round($r['revenue'] + $lt, 2);
        if (reports_cost_known($l)) {
            $cost = round((float)$l['qty'] * (float)$l['cost_price'], 2);
            $r['cost'] = round($r['cost'] + $cost, 2);
            $r['profit'] = round($r['profit'] + $lt - $cost, 2);
        } else {
            $r['lines_without_cost']++;
        }
        $rows[$k] = $r;
    }
    foreach ($rows as $k => $r) {
        $rows[$k]['partial'] = $r['lines_without_cost'] > 0;
        $rows[$k]['known'] = $r['lines_without_cost'] < $r['lines'];
    }
    $rows = array_values($rows);
    usort($rows, static function (array $a, array $b): int {
        return [$a['currency'], -$a['revenue'], strtolower($a['item'])] <=> [$b['currency'], -$b['revenue'], strtolower($b['item'])];
    });
    return $rows;
}

// ---------------------------------------------------------------------------------------------------------------
// Database (SELECT only)
// ---------------------------------------------------------------------------------------------------------------

function reports_doc_sql(): string
{
    return 'SELECT d.id, d.kind, d.number, d.status, d.issue_date, d.due_date, d.currency, d.subtotal, d.discount, d.tax_rate, d.tax,
                   d.total, d.amount_paid, d.tenant_id, d.client_id, d.customer_name, d.customer_email, d.salesperson, d.sent_at,
                   COALESCE(c1.id, c2.id) AS client_ref, COALESCE(c1.name, c2.name, d.customer_name) AS client_name
            FROM cp_documents d
            LEFT JOIN cp_clients c1 ON c1.id = d.client_id
            LEFT JOIN cp_clients c2 ON c2.tenant_id = d.tenant_id';
}

// Invoices that count (sent, paid) issued in the period.
function reports_invoices(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare(reports_doc_sql() . " WHERE d.kind = 'invoice' AND d.status IN ('sent','paid') AND d.issue_date BETWEEN ? AND ? ORDER BY d.issue_date, d.id");
    $st->execute([$from, $to]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Quotations dated in the period (every status; quotations are never money owed).
function reports_quotes(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare(reports_doc_sql() . " WHERE d.kind = 'quote' AND d.issue_date BETWEEN ? AND ? ORDER BY d.issue_date, d.id");
    $st->execute([$from, $to]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Sent invoices with a balance left, today (any issue date).
function reports_open_invoices(PDO $pdo): array
{
    $st = $pdo->prepare(reports_doc_sql() . " WHERE d.kind = 'invoice' AND d.status = 'sent' AND d.total - d.amount_paid > ? ORDER BY d.due_date, d.id");
    $st->execute([reports_tol()]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Lines of the counting invoices issued in the period, with the saved item's category and SKU when picked from the catalog.
function reports_lines(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare(
        "SELECT i.id, i.document_id, i.catalog_item_id, i.sort, i.qty, i.item, i.description, i.unit_price, i.cost_price, i.line_total,
                d.number, d.issue_date, d.currency, d.status, d.discount AS doc_discount,
                COALESCE(c1.id, c2.id) AS client_ref, COALESCE(c1.name, c2.name, d.customer_name) AS client_name,
                cat.category, cat.sku
         FROM cp_document_items i
         JOIN cp_documents d ON d.id = i.document_id
         LEFT JOIN cp_clients c1 ON c1.id = d.client_id
         LEFT JOIN cp_clients c2 ON c2.tenant_id = d.tenant_id
         LEFT JOIN cp_catalog_items cat ON cat.id = i.catalog_item_id
         WHERE d.kind = 'invoice' AND d.status IN ('sent','paid') AND d.issue_date BETWEEN ? AND ?
         ORDER BY d.issue_date, d.id, i.sort, i.id"
    );
    $st->execute([$from, $to]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Every payment recorded in the period (that is what "collected" means): against an invoice, or a licence payment
// recorded on a business page (document_id NULL, number NULL).
function reports_payments(PDO $pdo, string $from, string $to): array
{
    $end = gmdate('Y-m-d', gmmktime(0, 0, 0, (int)substr($to, 5, 2), (int)substr($to, 8, 2) + 1, (int)substr($to, 0, 4)));
    $st = $pdo->prepare(
        "SELECT p.id, p.document_id, p.refund_of, p.amount, p.currency, p.method, p.reference, p.created_at, d.number,
                COALESCE(c1.id, c3.id, c2.id) AS client_ref,
                COALESCE(c1.name, c3.name, c2.name, d.customer_name, t.name, '') AS client_name
         FROM cp_payments p
         LEFT JOIN cp_documents d ON d.id = p.document_id
         LEFT JOIN cp_clients c1 ON c1.id = p.client_id
         LEFT JOIN cp_clients c3 ON c3.id = d.client_id
         LEFT JOIN cp_clients c2 ON c2.tenant_id = COALESCE(p.tenant_id, d.tenant_id)
         LEFT JOIN cp_tenants t ON t.id = COALESCE(p.tenant_id, d.tenant_id)
         WHERE p.created_at >= ? AND p.created_at < ? AND p.voided_at IS NULL
         ORDER BY p.created_at, p.id"
    );
    $st->execute([$from . ' 00:00:00', $end . ' 00:00:00']);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function reports_clients(PDO $pdo): array
{
    return $pdo->query(
        'SELECT c.id, c.kind, c.name, c.contact_name, c.email, c.cc_emails, c.phone, c.address, c.tax_number, c.currency,
                c.tenant_id, c.source, c.is_active, c.created_at, t.name AS tenant_name
         FROM cp_clients c LEFT JOIN cp_tenants t ON t.id = c.tenant_id ORDER BY c.name, c.id'
    )->fetchAll(PDO::FETCH_ASSOC);
}

// Everything the page and the exports need for one period.
function reports_load(PDO $pdo, array $period, string $today): array
{
    $inv = reports_invoices($pdo, $period['from'], $period['to']);
    $pay = reports_payments($pdo, $period['from'], $period['to']);
    $open = reports_open_invoices($pdo);
    $lines = reports_lines($pdo, $period['from'], $period['to']);
    $aged = reports_aged_debt($open, $today);
    return [
        'period' => $period, 'today' => $today,
        'invoices' => $inv, 'payments' => $pay, 'open' => $open, 'lines' => $lines,
        'cards' => reports_cards($inv, $pay, $open, $lines, $today),
        'aged' => $aged, 'aged_totals' => reports_aged_totals($aged),
        'months' => reports_by_month($inv, $pay, $lines),
        'by_client' => reports_by_client($inv, $pay),
        'products' => reports_by_product($lines, 'item'),
        'categories' => reports_by_product($lines, 'category'),
        // Part 3: free trials started / converted / lost in the period, and the conversion rate (app/trials.php).
        'trials' => function_exists('reports_trials') ? reports_trials(reports_trial_tenants($pdo), $period['from'], $period['to'], $today) : null,
    ];
}

// ---------------------------------------------------------------------------------------------------------------
// Excel sheets
// ---------------------------------------------------------------------------------------------------------------

// Export key => [sheet name, needs] (needs: data key loaded by reports_load, or 'quotes' / 'clients' loaded on demand).
function reports_exports(): array
{
    return [
        'invoices' => 'Invoices', 'lines' => 'Invoice lines', 'payments' => 'Payments', 'quotes' => 'Quotations',
        'clients' => 'Clients', 'aged' => 'Aged debt', 'months' => 'Sales by month', 'products' => 'Sales by product',
        'by_client' => 'Sales by client', 'categories' => 'Sales by category',
    ];
}

// The sheets in "Export everything", in order.
function reports_everything(): array
{
    return ['invoices', 'lines', 'payments', 'quotes', 'clients', 'aged', 'months', 'products'];
}

function reports_profit_note(array $r): string
{
    if (empty($r['known'])) {
        return 'No cost prices';
    }
    return !empty($r['partial']) ? 'Partial: ' . (int)$r['lines_without_cost'] . ' line(s) without cost' : '';
}

/** One sheet for xlsx_build. $extra: 'quotes' and 'clients' rows when those sheets are wanted. */
function reports_sheet(string $which, array $data, array $extra = []): ?array
{
    $s = static fn(string $t, int $w = 20): array => ['title' => $t, 'type' => 'string', 'width' => $w];
    $m = static fn(string $t, int $w = 15): array => ['title' => $t, 'type' => 'money', 'width' => $w];
    $n = static fn(string $t, int $w = 10): array => ['title' => $t, 'type' => 'number', 'width' => $w];
    $d = static fn(string $t, int $w = 12): array => ['title' => $t, 'type' => 'date', 'width' => $w];
    $name = reports_exports()[$which] ?? null;
    if ($name === null) {
        return null;
    }
    $today = (string)$data['today'];
    $cols = [];
    $rows = [];
    switch ($which) {
        case 'invoices':
            $cols = [$s('Invoice no.', 12), $d('Date'), $d('Due date'), $s('Client', 30), $s('Status', 10), $s('Currency', 9),
                $m('Subtotal'), $m('Discount'), $m('Tax'), $m('Total'), $m('Paid'), $m('Balance'), $s('Overdue', 9), $s('Salesperson', 16)];
            foreach ($data['invoices'] as $r) {
                $bal = $r['status'] === 'sent' ? max(0.0, reports_balance($r)) : 0.0;
                $late = $r['status'] === 'sent' && $bal > reports_tol() && reports_days_overdue($r['due_date'], $today) > 0;
                $rows[] = [$r['number'], $r['issue_date'], $r['due_date'], $r['client_name'], ucfirst((string)$r['status']), $r['currency'],
                    $r['subtotal'], $r['discount'], $r['tax'], $r['total'], $r['amount_paid'], $bal, $late ? 'Yes' : 'No', $r['salesperson']];
            }
            break;
        case 'lines':
            $cols = [$s('Invoice no.', 12), $d('Date'), $s('Client', 28), $s('Currency', 9), $s('Category', 16), $s('SKU', 12), $s('Item', 28),
                $s('Description', 40), $n('Qty', 8), $m('Unit price'), $m('Line total'), $m('Cost price'), $m('Profit')];
            foreach ($data['lines'] as $r) {
                $known = reports_cost_known($r);
                $rows[] = [$r['number'], $r['issue_date'], $r['client_name'], $r['currency'], reports_category_label($r['category'] ?? null),
                    $r['sku'], $r['item'], $r['description'], $r['qty'], $r['unit_price'], $r['line_total'],
                    $known ? $r['cost_price'] : null, $known ? round((float)$r['line_total'] - round((float)$r['qty'] * (float)$r['cost_price'], 2), 2) : null];
            }
            break;
        case 'payments':
            $cols = [$d('Date'), $s('Invoice no.', 12), $s('Client', 30), $s('Currency', 9), $m('Amount'), $s('Method', 14), $s('Reference', 30)];
            foreach ($data['payments'] as $r) {
                $rows[] = [$r['created_at'], ((float)$r['amount'] < 0 ? 'Refund: ' : '') . ($r['number'] ?? 'Licence payment'), $r['client_name'], $r['currency'], $r['amount'], $r['method'], $r['reference']];
            }
            break;
        case 'quotes':
            $cols = [$s('Quotation no.', 13), $d('Date'), $d('Valid until'), $s('Client', 30), $s('Status', 11), $s('Currency', 9), $m('Total')];
            foreach ($extra['quotes'] ?? [] as $r) {
                $rows[] = [$r['number'], $r['issue_date'], $r['due_date'], $r['client_name'], ucfirst((string)$r['status']), $r['currency'], $r['total']];
            }
            break;
        case 'clients':
            $cols = [$s('Client', 30), $s('Kind', 11), $s('Contact', 22), $s('Email', 28), $s('CC emails', 28), $s('Phone', 16), $s('Address', 30),
                $s('Tax number', 14), $s('Currency', 9), $s('Hosted business', 24), $s('Source', 12), $s('Active', 8), $d('Added')];
            foreach ($extra['clients'] ?? [] as $r) {
                $rows[] = [$r['name'], ucfirst((string)$r['kind']), $r['contact_name'], $r['email'], $r['cc_emails'], $r['phone'],
                    str_replace(["\r\n", "\r", "\n"], ', ', (string)$r['address']), $r['tax_number'], $r['currency'], $r['tenant_name'],
                    $r['source'], (int)$r['is_active'] === 1 ? 'Yes' : 'No', $r['created_at']];
            }
            break;
        case 'aged':
            $cols = [$s('Client', 30), $s('Currency', 9)];
            foreach (REPORTS_BUCKETS as $label) {
                $cols[] = $m($label, 14);
            }
            $cols[] = $m('Total');
            $cols[] = $n('Invoices', 9);
            $cols[] = $d('Oldest due');
            foreach ($data['aged'] as $r) {
                $row = [$r['client'], $r['currency']];
                foreach (array_keys(REPORTS_BUCKETS) as $b) {
                    $row[] = $r[$b];
                }
                $rows[] = array_merge($row, [$r['total'], $r['invoices'], $r['oldest_due']]);
            }
            break;
        case 'months':
            $cols = [$s('Month', 10), $s('Currency', 9), $n('Invoices', 9), $m('Invoiced'), $m('Collected'), $m('Profit'), $s('Profit note', 28)];
            foreach ($data['months'] as $r) {
                $rows[] = [$r['month'], $r['currency'], $r['invoices'], $r['invoiced'], $r['collected'], $r['known'] ? $r['profit'] : null, reports_profit_note($r)];
            }
            break;
        case 'products':
        case 'categories':
            $cols = $which === 'products'
                ? [$s('Currency', 9), $s('Category', 16), $s('SKU', 12), $s('Item', 30), $n('Qty', 9), $n('Lines', 8)]
                : [$s('Currency', 9), $s('Category', 18), $n('Lines', 8)];
            $cols = array_merge($cols, [$m('Revenue'), $m('Cost'), $m('Profit'), $s('Profit note', 28)]);
            foreach ($data[$which] as $r) {
                $row = $which === 'products' ? [$r['currency'], $r['category'], $r['sku'], $r['item'], $r['qty'], $r['lines']] : [$r['currency'], $r['category'], $r['lines']];
                $rows[] = array_merge($row, [$r['revenue'], $r['known'] ? $r['cost'] : null, $r['known'] ? $r['profit'] : null, reports_profit_note($r)]);
            }
            break;
        case 'by_client':
            $cols = [$s('Client', 30), $s('Currency', 9), $n('Invoices', 9), $m('Invoiced'), $m('Still owed'), $m('Collected')];
            foreach ($data['by_client'] as $r) {
                $rows[] = [$r['client'], $r['currency'], $r['invoices'], $r['invoiced'], $r['balance'], $r['collected']];
            }
            break;
    }
    return ['name' => $name, 'columns' => $cols, 'rows' => $rows];
}

function reports_filename(string $what, array $period): string
{
    return 'ProManaged IT ' . $what . ' ' . $period['from'] . ' to ' . $period['to'] . '.xlsx';
}
