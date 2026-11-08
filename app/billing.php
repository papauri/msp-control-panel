<?php
// Billing (owner 2026-11-09): invoices, quotations, payments against invoices, statements, reminders, company settings.
// Contract: BILLING.md. Nothing runs at include time. Money is rounded to 2 dp and compared with BILLING_TOLERANCE.

const BILLING_TOLERANCE = 0.005;

// ---------------------------------------------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------------------------------------------

function billing_setting_defaults(): array
{
    return [
        'company_name' => 'ProManaged IT',
        'company_address' => "Offaly,\nIreland",
        'company_emails' => "info@promanaged-it.com\nsales@promanaged-it.com",
        'company_phone' => '',
        'company_website' => 'https://promanaged-it.com',
        'salesperson' => 'John Paul',
        'default_currency' => 'MWK',
        'invoice_next_number' => '170134',
        'quote_next_number' => 'Q-1001',
        'invoice_due_days' => '7',
        'quote_valid_days' => '30',
        'tax_rate' => '0',
        'tax_label' => 'VAT',
        'thank_you' => 'Thank you for your business.',
        'payment_details' => "Eur/\$ Account\n-----------------\nBank Name: Permanent TSB\nAccount Name: PROMANAGED IT\n"
            . "IBAN: IE04IPBS99072135995698\nBIC: IPBSIE2D\nBank account number: 35995698\nBranch code: 990721\n"
            . "Bank Code: IPBS\nBranch Address: Address: 56-59 ST. STEPHEN'S GREEN, DUBLIN, D02 H489\n\n"
            . "MWK Account\n-----------------\nStandard Bank\nBranch: CAPITAL CITY\nAccount Name: JOHN PAUL CHIRWA\n"
            . "Account Numbers: 9100006400512\n----------------------------------\n"
            . 'Please contact us for more information about payment options.',
        'email_signature' => "John Paul\nProManaged IT\ninfo@promanaged-it.com",
        'reminder_days' => '3,0,7,14',
        'auto_reminders' => '1',
        'brand_color' => '#D40000',
        'brand_navy' => '#1B3A5C',
    ];
}

// Static cache of the stored settings. $reset drops it (after a save, or inside a rolled-back test).
function billing_settings_cache(?PDO $pdo = null, bool $reset = false): array
{
    static $cache = null;
    if ($reset) {
        $cache = null;
        return [];
    }
    if ($cache === null) {
        $cache = [];
        $pdo = $pdo ?? db();
        foreach ($pdo->query('SELECT setting_key, setting_value FROM cp_settings') as $r) {
            $cache[(string)$r['setting_key']] = (string)$r['setting_value'];
        }
    }
    return $cache;
}

function billing_setting(string $key): string
{
    $stored = billing_settings_cache();
    if (array_key_exists($key, $stored)) {
        return $stored[$key];
    }
    $d = billing_setting_defaults();
    return $d[$key] ?? '';
}

function billing_settings_all(): array
{
    $out = billing_setting_defaults();
    foreach (billing_settings_cache() as $k => $v) {
        if (array_key_exists($k, $out)) {
            $out[$k] = $v;
        }
    }
    return $out;
}

// Pure. Returns [errors, clean] for the settings form. Only known keys are kept.
function billing_settings_validate(array $in): array
{
    $err = [];
    $clean = [];
    $defaults = billing_setting_defaults();
    $limits = ['payment_details' => 4000, 'company_address' => 1000, 'company_emails' => 1000, 'email_signature' => 1000, 'thank_you' => 500];
    foreach ($defaults as $k => $d) {
        if (!array_key_exists($k, $in)) {
            continue;
        }
        $v = is_string($in[$k]) ? str_replace("\r\n", "\n", $in[$k]) : '';
        $v = mb_substr(trim($v, " \t\n\r\0\x0B"), 0, $limits[$k] ?? 190);
        $clean[$k] = $v;
    }
    if (isset($clean['company_name']) && $clean['company_name'] === '') {
        $err[] = 'Company name is required.';
    }
    if (isset($clean['company_emails'])) {
        foreach (billing_parse_emails($clean['company_emails'], true) as $bad) {
            $err[] = 'Not a valid email address: ' . $bad;
        }
    }
    if (isset($clean['company_website']) && $clean['company_website'] !== ''
        && !preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $clean['company_website'])) {
        $err[] = 'Website must start with http:// or https://';
    }
    if (isset($clean['default_currency'])) {
        $clean['default_currency'] = strtoupper($clean['default_currency']);
        if (!preg_match('/^[A-Z]{3}$/', $clean['default_currency'])) {
            $err[] = 'Default currency must be a 3-letter code such as MWK.';
        }
    }
    foreach (['invoice_next_number' => 'Next invoice number', 'quote_next_number' => 'Next quotation number'] as $k => $label) {
        if (isset($clean[$k]) && !preg_match('/^[A-Za-z0-9\/_-]{0,30}[0-9]{1,9}$/', $clean[$k])) {
            $err[] = $label . ' must end in digits (for example 170134 or Q-1001), letters, digits, - / _ only.';
        }
    }
    foreach (['invoice_due_days' => 'Invoice due days', 'quote_valid_days' => 'Quotation valid days'] as $k => $label) {
        if (isset($clean[$k]) && (!preg_match('/^\d{1,3}$/', $clean[$k]) || (int)$clean[$k] > 365)) {
            $err[] = $label . ' must be a whole number from 0 to 365.';
        }
    }
    if (isset($clean['tax_rate'])) {
        if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $clean['tax_rate'])) {
            $err[] = 'Tax rate must be a percentage from 0 to 99.99, for example 16.5.';
        }
    }
    if (isset($clean['reminder_days'])) {
        $clean['reminder_days'] = preg_replace('/\s+/', '', $clean['reminder_days']);
        if ($clean['reminder_days'] !== '' && !preg_match('/^\d{1,3}(,\d{1,3})*$/', $clean['reminder_days'])) {
            $err[] = 'Reminder days must be whole numbers separated by commas, for example 3,0,7,14.';
        }
    }
    if (array_key_exists('auto_reminders', $clean)) {
        $clean['auto_reminders'] = $clean['auto_reminders'] === '1' ? '1' : '0';
    }
    foreach (['brand_color', 'brand_navy'] as $k) {
        if (isset($clean[$k])) {
            $clean[$k] = strtoupper($clean[$k]);
            if (!preg_match('/^#[0-9A-F]{6}$/', $clean[$k])) {
                $err[] = 'Colours must be written like #D40000.';
            }
        }
    }
    return [$err, $clean];
}

function billing_settings_save(PDO $pdo, array $kv, int $adminId): void
{
    $defaults = billing_setting_defaults();
    $before = billing_settings_all();
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $st = $pdo->prepare('INSERT INTO cp_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $changedBefore = [];
        $changedAfter = [];
        foreach ($kv as $k => $v) {
            if (!is_string($k) || !array_key_exists($k, $defaults)) {
                continue;
            }
            $v = (string)$v;
            $st->execute([$k, $v]);
            if (($before[$k] ?? null) !== $v) {
                $changedBefore[$k] = $before[$k] ?? null;
                $changedAfter[$k] = $v;
            }
        }
        if ($changedAfter) {
            audit_log($pdo, $adminId, 'billing_settings_saved', null, $changedBefore, $changedAfter);
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        billing_settings_cache(null, true);
    }
}

// Lines of text, trimmed, empty lines dropped.
function billing_lines(string $text): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $l) {
        $l = trim($l);
        if ($l !== '') {
            $out[] = $l;
        }
    }
    return $out;
}

// Splits on commas, semicolons, spaces and new lines. $invalidOnly returns the entries that are not valid emails.
function billing_parse_emails(string $text, bool $invalidOnly = false): array
{
    $ok = [];
    $bad = [];
    foreach (preg_split('/[\s,;]+/', $text) as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        if (filter_var($p, FILTER_VALIDATE_EMAIL) && strlen($p) <= 190) {
            $ok[strtolower($p)] = $ok[strtolower($p)] ?? $p;
        } else {
            $bad[] = $p;
        }
    }
    return $invalidOnly ? $bad : array_values($ok);
}

function billing_company(): array
{
    $s = billing_settings_all();
    return [
        'name' => $s['company_name'],
        'address_lines' => billing_lines($s['company_address']),
        'emails' => billing_parse_emails($s['company_emails']),
        'phone' => $s['company_phone'],
        'website' => $s['company_website'],
        'payment_details' => $s['payment_details'],
        'thank_you' => $s['thank_you'],
        'salesperson' => $s['salesperson'],
        'brand_color' => $s['brand_color'],
        'brand_navy' => $s['brand_navy'],
        'logo_path' => APP_ROOT . '/public/assets/logo.png',
        // Extra keys (not in the contract list, harmless to ignore):
        'tax_label' => $s['tax_label'],
        'email_signature' => $s['email_signature'],
    ];
}

// ---------------------------------------------------------------------------------------------------------------
// Pure formatting and maths
// ---------------------------------------------------------------------------------------------------------------

function billing_money(float $amount, string $currency): string
{
    $a = round($amount, 2);
    if (abs($a) < BILLING_TOLERANCE) {
        $a = 0.0;
    }
    return ($a < 0 ? '-' : '') . strtoupper($currency) . number_format(abs($a), 2, '.', ',');
}

function billing_date(?string $ymd): string
{
    if ($ymd === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return '';
    }
    return $m[3] . '/' . $m[2] . '/' . $m[1];
}

function billing_line_total(float $qty, float $unitPrice): float
{
    return round($qty * $unitPrice, 2);
}

/**
 * Pure. $items: rows with qty, unit_price (others kept). Returns items with line_total set, and the totals:
 * subtotal = sum of line totals; tax = (subtotal - discount) * rate / 100; total = subtotal - discount + tax.
 */
function billing_calc_totals(array $items, float $discount, float $taxRate): array
{
    $sub = 0.0;
    $out = [];
    foreach ($items as $it) {
        $it['qty'] = round((float)($it['qty'] ?? 0), 2);
        $it['unit_price'] = round((float)($it['unit_price'] ?? 0), 2);
        $it['line_total'] = billing_line_total($it['qty'], $it['unit_price']);
        $sub = round($sub + $it['line_total'], 2);
        $out[] = $it;
    }
    $discount = round($discount, 2);
    $taxRate = round($taxRate, 2);
    $tax = round(($sub - $discount) * $taxRate / 100, 2);
    $total = round($sub - $discount + $tax, 2);
    return ['items' => $out, 'subtotal' => $sub, 'discount' => $discount, 'tax_rate' => $taxRate, 'tax' => $tax, 'total' => $total];
}

function billing_balance(array $doc): float
{
    return round((float)($doc['total'] ?? 0) - (float)($doc['amount_paid'] ?? 0), 2);
}

// Pure. "170134" -> "170135", "Q-1001" -> "Q-1002", "INV-0099" -> "INV-0100", "Q-9999" -> "Q-10000", "ABC" -> "ABC1".
function billing_number_increment(string $n): string
{
    if (!preg_match('/^(.*?)(\d+)$/', $n, $m)) {
        return $n . '1';
    }
    $digits = $m[2];
    $next = (string)((int)ltrim($digits, '0') + 1);
    if ($digits !== '' && ltrim($digits, '0') === '') {
        $next = '1';
    }
    return $m[1] . str_pad($next, strlen($digits), '0', STR_PAD_LEFT);
}

// Whole days from $from to $to (Y-m-d), negative when $to is earlier.
function billing_days_between(string $from, string $to): int
{
    $a = new DateTimeImmutable(substr($from, 0, 10), new DateTimeZone('UTC'));
    $b = new DateTimeImmutable(substr($to, 0, 10), new DateTimeZone('UTC'));
    return (int)$a->diff($b)->format('%r%a');
}

/**
 * Pure. Reads the reminder_days setting. The values listed BEFORE the 0 are days before the due date, the 0 is the due
 * date itself, the values AFTER the 0 are days after it ("3,0,7,14" = 3 days before, on the day, 7 and 14 days after).
 * With no 0 in the list every value counts as days after the due date.
 * Returns signed offsets (today minus due date): [-3, 0, 7, 14].
 */
function billing_reminder_offsets(string $setting): array
{
    $vals = [];
    foreach (explode(',', $setting) as $p) {
        $p = trim($p);
        if ($p !== '' && ctype_digit($p)) {
            $vals[] = (int)$p;
        }
    }
    $zero = array_search(0, $vals, true);
    $out = [];
    foreach ($vals as $i => $v) {
        if ($v === 0) {
            $out[0] = 0;
        } elseif ($zero !== false && $i < $zero) {
            $out[-$v] = -$v;
        } else {
            $out[$v] = $v;
        }
    }
    ksort($out);
    return array_values($out);
}

/**
 * Pure. If a reminder is due today for an invoice due on $dueDate, returns the signed day offset (today minus due:
 * negative = before due, 0 = on the due date, positive = days overdue); otherwise null. $lastReminder (Y-m-d or datetime)
 * on the same day as $today means it has already been reminded today.
 */
function billing_reminder_match(string $dueDate, string $today, string $setting, ?string $lastReminder = null): ?int
{
    if ($lastReminder !== null && $lastReminder !== '' && substr($lastReminder, 0, 10) === $today) {
        return null;
    }
    $days = billing_days_between($dueDate, $today);
    return in_array($days, billing_reminder_offsets($setting), true) ? $days : null;
}

/**
 * Pure. Invoice lines for a business's licence: one line, qty = months, unit price = monthly fee.
 */
function billing_subscription_lines(array $tenant, int $months): array
{
    $months = max(1, $months);
    $plan = trim((string)($tenant['plan'] ?? ''));
    $item = $plan !== '' ? $plan . ' licence' : 'Licence';
    $mods = [];
    $labels = catalog_modules();
    foreach (catalog_tenant_modules($tenant) as $k) {
        $mods[] = $labels[$k] ?? $k;
    }
    $type = catalog_type_label((string)($tenant['business_type'] ?? 'custom'));
    $desc = 'Hotel management system licence: ' . $type;
    if (!empty($tenant['domain'])) {
        $desc .= ' (' . $tenant['domain'] . ')';
    }
    $desc .= ".\nModules: " . ($mods ? implode(', ', $mods) : 'none') . '.'
        . "\n" . $months . ' month' . ($months === 1 ? '' : 's') . ' at the monthly fee.';
    $price = round((float)($tenant['monthly_fee'] ?? 0), 2);
    return [[
        'qty' => (float)$months,
        'item' => $item,
        'description' => $desc,
        'unit_price' => $price,
        'line_total' => billing_line_total((float)$months, $price),
    ]];
}

function billing_kind_label(string $kind): string
{
    return $kind === 'quote' ? 'Quotation' : 'Invoice';
}

function billing_statuses(string $kind): array
{
    return $kind === 'quote'
        ? ['draft' => 'Draft', 'sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Declined', 'expired' => 'Expired', 'converted' => 'Converted']
        : ['draft' => 'Draft', 'sent' => 'Sent', 'paid' => 'Paid', 'void' => 'Void'];
}

function billing_payment_methods(): array
{
    return ['bank_transfer' => 'Bank transfer', 'mobile_money' => 'Mobile money', 'cash' => 'Cash', 'card' => 'Card', 'cheque' => 'Cheque', 'other' => 'Other'];
}

/**
 * Pure. What may still change on a document: 'all', 'lines' (sent unpaid invoice) or 'none'.
 */
function billing_editable(array $doc): string
{
    $kind = (string)($doc['kind'] ?? 'invoice');
    $status = (string)($doc['status'] ?? 'draft');
    if ($kind === 'quote') {
        return $status === 'converted' ? 'none' : 'all';
    }
    if ($status === 'draft' && (float)($doc['amount_paid'] ?? 0) <= BILLING_TOLERANCE) {
        return 'all';
    }
    if ($status === 'sent' && (float)($doc['amount_paid'] ?? 0) <= BILLING_TOLERANCE) {
        return 'lines';
    }
    return 'none';
}

/**
 * Pure. Validates a posted document and its lines. Returns [errors, doc, items]. Blank lines are dropped.
 * $doc keys: kind, tenant_id, customer_name, customer_contact, customer_email, customer_address, issue_date, due_date,
 * currency, salesperson, notes, discount, tax_rate, extend_months.
 */
function billing_doc_validate(array $in, array $lines): array
{
    $err = [];
    $s = static function ($k, int $max) use ($in): string {
        $v = isset($in[$k]) && (is_string($in[$k]) || is_numeric($in[$k])) ? trim((string)$in[$k]) : '';
        return mb_substr(str_replace("\r\n", "\n", $v), 0, $max);
    };
    $kind = ($in['kind'] ?? '') === 'quote' ? 'quote' : 'invoice';
    $tid = $s('tenant_id', 10);
    $doc = [
        'kind' => $kind,
        'tenant_id' => ctype_digit($tid) && (int)$tid > 0 ? (int)$tid : null,
        'customer_name' => $s('customer_name', 190),
        'customer_contact' => $s('customer_contact', 120),
        'customer_email' => $s('customer_email', 190),
        'customer_address' => $s('customer_address', 1000),
        'issue_date' => $s('issue_date', 10),
        'due_date' => $s('due_date', 10),
        'currency' => strtoupper($s('currency', 3)),
        'salesperson' => $s('salesperson', 120),
        'notes' => $s('notes', 2000),
    ];
    if ($doc['customer_name'] === '') {
        $err[] = 'Customer (business) name is required.';
    }
    if ($doc['customer_email'] !== '' && !filter_var($doc['customer_email'], FILTER_VALIDATE_EMAIL)) {
        $err[] = 'Customer email is not a valid email address.';
    }
    if (!tenant_valid_date($doc['issue_date'])) {
        $err[] = 'Date must be a valid date.';
    }
    if ($doc['due_date'] === '') {
        $doc['due_date'] = null;
    } elseif (!tenant_valid_date($doc['due_date'])) {
        $err[] = ($kind === 'quote' ? 'Valid until' : 'Due date') . ' must be a valid date.';
    } elseif (tenant_valid_date($doc['issue_date']) && $doc['due_date'] < $doc['issue_date']) {
        $err[] = ($kind === 'quote' ? 'Valid until' : 'Due date') . ' cannot be before the date of the ' . strtolower(billing_kind_label($kind)) . '.';
    }
    if (!preg_match('/^[A-Z]{3}$/', $doc['currency'])) {
        $err[] = 'Currency must be a 3-letter code such as MWK.';
    }
    $disc = $s('discount', 20);
    if ($disc === '') {
        $disc = '0';
    }
    if (!preg_match('/^\d{1,11}(\.\d{1,2})?$/', $disc)) {
        $err[] = 'Discount must be an amount, for example 5000 or 0.';
        $disc = '0';
    }
    $doc['discount'] = round((float)$disc, 2);
    $rate = $s('tax_rate', 10);
    if ($rate === '') {
        $rate = '0';
    }
    if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $rate)) {
        $err[] = 'Tax rate must be a percentage from 0 to 99.99.';
        $rate = '0';
    }
    $doc['tax_rate'] = round((float)$rate, 2);
    $ext = $s('extend_months', 3);
    if ($ext === '') {
        $ext = '0';
    }
    if (!ctype_digit($ext) || (int)$ext > 60) {
        $err[] = 'Extend licence by must be a whole number of months from 0 to 60.';
        $ext = '0';
    }
    $doc['extend_months'] = (int)$ext;
    if ($doc['extend_months'] > 0 && $doc['tenant_id'] === null) {
        $err[] = 'Extending a licence needs a business: choose the business or set the months to 0.';
    }

    $items = [];
    $n = 0;
    foreach ($lines as $l) {
        $n++;
        if (!is_array($l)) {
            continue;
        }
        $g = static function ($k, int $max) use ($l): string {
            $v = isset($l[$k]) && (is_string($l[$k]) || is_numeric($l[$k])) ? trim((string)$l[$k]) : '';
            return mb_substr(str_replace("\r\n", "\n", $v), 0, $max);
        };
        $item = $g('item', 190);
        $desc = $g('description', 2000);
        $qty = $g('qty', 12);
        $price = $g('unit_price', 20);
        if ($item === '' && $desc === '' && ($price === '' || (float)$price == 0.0)) {
            continue; // blank line
        }
        if ($item === '') {
            $err[] = 'Line ' . $n . ': the item name is required.';
        }
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $qty) || (float)$qty <= 0) {
            $err[] = 'Line ' . $n . ': quantity must be a number above 0, for example 1 or 2.5.';
            $qty = '1';
        }
        if ($price === '') {
            $price = '0';
        }
        if (!preg_match('/^-?\d{1,11}(\.\d{1,2})?$/', $price)) {
            $err[] = 'Line ' . $n . ': unit price must be an amount, for example 100000.';
            $price = '0';
        }
        $items[] = ['qty' => (float)$qty, 'item' => $item, 'description' => $desc, 'unit_price' => (float)$price];
    }
    if (!$items) {
        $err[] = 'Add at least one line.';
    }
    if (count($items) > 100) {
        $err[] = 'At most 100 lines.';
    }
    $t = billing_calc_totals($items, $doc['discount'], $doc['tax_rate']);
    if ($t['subtotal'] > 0 && $doc['discount'] - $t['subtotal'] > BILLING_TOLERANCE) {
        $err[] = 'The discount cannot be more than the subtotal.';
    }
    if ($t['total'] < -BILLING_TOLERANCE) {
        $err[] = 'The total cannot be below zero.';
    }
    return [$err, $doc, $t['items']];
}

// ---------------------------------------------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------------------------------------------

/**
 * Reserves and returns the next number for $kind ('invoice' | 'quote'): the stored "next number" is returned and its
 * increment stored, under a row lock. Numbers already used are skipped. Joins the caller's transaction if one is open.
 */
function billing_next_number(PDO $pdo, string $kind): string
{
    $kind = $kind === 'quote' ? 'quote' : 'invoice';
    $key = $kind . '_next_number';
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare('INSERT IGNORE INTO cp_settings (setting_key, setting_value) VALUES (?, ?)')
            ->execute([$key, billing_setting_defaults()[$key]]);
        $st = $pdo->prepare('SELECT setting_value FROM cp_settings WHERE setting_key = ? FOR UPDATE');
        $st->execute([$key]);
        $num = trim((string)$st->fetchColumn());
        if ($num === '') {
            $num = billing_setting_defaults()[$key];
        }
        $used = $pdo->prepare('SELECT 1 FROM cp_documents WHERE kind = ? AND number = ?');
        for ($i = 0; $i < 1000; $i++) {
            $used->execute([$kind, $num]);
            if (!$used->fetchColumn()) {
                break;
            }
            $num = billing_number_increment($num);
        }
        $pdo->prepare('UPDATE cp_settings SET setting_value = ? WHERE setting_key = ?')
            ->execute([billing_number_increment($num), $key]);
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        billing_settings_cache(null, true);
    }
    return $num;
}

function billing_doc_row(PDO $pdo, int $id, bool $forUpdate = false): ?array
{
    $st = $pdo->prepare('SELECT * FROM cp_documents WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

function billing_doc_items(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM cp_document_items WHERE document_id = ? ORDER BY sort, id');
    $st->execute([$id]);
    return $st->fetchAll();
}

// Adds balance and is_overdue to a document row.
function billing_doc_decorate(array $d, ?string $today = null): array
{
    $today = $today ?? status_today();
    $d['balance'] = billing_balance($d);
    $d['is_overdue'] = $d['kind'] === 'invoice' && $d['status'] === 'sent' && !empty($d['due_date'])
        && $d['due_date'] < $today && $d['balance'] > BILLING_TOLERANCE;
    return $d;
}

function billing_doc(PDO $pdo, int $id): ?array
{
    $d = billing_doc_row($pdo, $id);
    if (!$d) {
        return null;
    }
    $d = billing_doc_decorate($d);
    $d['items'] = billing_doc_items($pdo, $id);
    $d['tenant'] = $d['tenant_id'] !== null ? tenant_find($pdo, (int)$d['tenant_id']) : null;
    return $d;
}

/**
 * Insert ($doc['id'] empty) or update a document and replace its lines; totals are recalculated here.
 * $doc as billing_doc_validate returns it (plus 'id' and, for new ones, 'kind'). $items: qty, item, description, unit_price.
 * Rules: paid / void invoices and converted quotes cannot change; a sent unpaid invoice may change its lines only;
 * the total may not fall below what has been paid. Throws InvalidArgumentException with a message for the owner.
 */
function billing_doc_save(PDO $pdo, array $doc, array $items, int $adminId): int
{
    $id = isset($doc['id']) ? (int)$doc['id'] : 0;
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        if (!empty($doc['tenant_id']) && !tenant_find($pdo, (int)$doc['tenant_id'])) {
            throw new InvalidArgumentException('That business no longer exists.');
        }
        $cols = ['tenant_id', 'customer_name', 'customer_contact', 'customer_email', 'customer_address', 'issue_date', 'due_date',
            'currency', 'salesperson', 'notes', 'discount', 'tax_rate', 'extend_months'];
        $before = null;
        if ($id > 0) {
            $before = billing_doc_row($pdo, $id, true);
            if (!$before) {
                throw new InvalidArgumentException('That document no longer exists.');
            }
            $mode = billing_editable($before);
            if ($mode === 'none') {
                throw new InvalidArgumentException('This ' . strtolower(billing_kind_label($before['kind'])) . ' is ' . $before['status'] . ' and can no longer be changed.');
            }
            if ($mode === 'lines') {
                // Only the lines change; everything else stays as it was sent.
                foreach ($cols as $c) {
                    $doc[$c] = $before[$c];
                }
            }
            $doc['kind'] = $before['kind'];
            $before['items'] = billing_doc_items($pdo, $id);
        }
        $kind = ($doc['kind'] ?? '') === 'quote' ? 'quote' : 'invoice';
        $t = billing_calc_totals($items, (float)($doc['discount'] ?? 0), (float)($doc['tax_rate'] ?? 0));
        $paid = $before ? (float)$before['amount_paid'] : 0.0;
        if ($paid - $t['total'] > BILLING_TOLERANCE) {
            throw new InvalidArgumentException('The total cannot be less than the amount already paid.');
        }
        $vals = [
            !empty($doc['tenant_id']) ? (int)$doc['tenant_id'] : null,
            (string)($doc['customer_name'] ?? ''),
            ($doc['customer_contact'] ?? '') !== '' ? (string)$doc['customer_contact'] : null,
            ($doc['customer_email'] ?? '') !== '' ? (string)$doc['customer_email'] : null,
            ($doc['customer_address'] ?? '') !== '' ? (string)$doc['customer_address'] : null,
            (string)$doc['issue_date'],
            ($doc['due_date'] ?? '') !== '' ? (string)$doc['due_date'] : null,
            strtoupper((string)($doc['currency'] ?? 'MWK')),
            ($doc['salesperson'] ?? '') !== '' ? (string)$doc['salesperson'] : null,
            ($doc['notes'] ?? '') !== '' ? (string)$doc['notes'] : null,
            number_format($t['discount'], 2, '.', ''),
            number_format($t['tax_rate'], 2, '.', ''),
            (int)($doc['extend_months'] ?? 0),
        ];
        $money = [
            number_format($t['subtotal'], 2, '.', ''),
            number_format($t['tax'], 2, '.', ''),
            number_format($t['total'], 2, '.', ''),
        ];
        if ($id === 0) {
            $number = billing_next_number($pdo, $kind);
            $st = $pdo->prepare(
                'INSERT INTO cp_documents (kind, number, ' . implode(', ', $cols) . ', subtotal, tax, total, source_id, created_by, status)
                 VALUES (?, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?, ?, ?, ?, ?, \'draft\')'
            );
            $st->execute(array_merge([$kind, $number], $vals, $money, [!empty($doc['source_id']) ? (int)$doc['source_id'] : null, $adminId ?: null]));
            $id = (int)$pdo->lastInsertId();
        } else {
            $set = implode(', ', array_map(static fn($c) => $c . ' = ?', $cols));
            $pdo->prepare('UPDATE cp_documents SET ' . $set . ', subtotal = ?, tax = ?, total = ? WHERE id = ?')
                ->execute(array_merge($vals, $money, [$id]));
            $pdo->prepare('DELETE FROM cp_document_items WHERE document_id = ?')->execute([$id]);
        }
        $ins = $pdo->prepare('INSERT INTO cp_document_items (document_id, sort, qty, item, description, unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($t['items'] as $i => $it) {
            $ins->execute([
                $id, $i + 1, number_format($it['qty'], 2, '.', ''), mb_substr((string)$it['item'], 0, 190),
                ($it['description'] ?? '') !== '' ? (string)$it['description'] : null,
                number_format($it['unit_price'], 2, '.', ''), number_format($it['line_total'], 2, '.', ''),
            ]);
        }
        $after = billing_doc_row($pdo, $id);
        $after['items'] = billing_doc_items($pdo, $id);
        audit_log($pdo, $adminId, $kind === 'quote' ? 'quote_saved' : 'invoice_saved',
            $after['tenant_id'] !== null ? (int)$after['tenant_id'] : null, $before, $after);
        if ($own) {
            $pdo->commit();
        }
        return $id;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Records money received against an invoice. When it brings the balance to zero the invoice becomes paid and, if it
 * carries extend_months and a business, that business's paid_until moves on exactly as "Record payment" does on the
 * business page (from the later of today and the current paid_until). One transaction, audited.
 * A business is required: every payment row belongs to a business.
 */
function billing_record_payment(PDO $pdo, int $docId, float $amount, string $reference, string $method, int $adminId): array
{
    $res = ['ok' => false, 'error' => '', 'payment_id' => 0, 'paid_in_full' => false];
    $amount = round($amount, 2);
    $reference = trim($reference);
    $methods = billing_payment_methods();
    if (!isset($methods[$method])) {
        $res['error'] = 'Choose how the money was paid.';
        return $res;
    }
    if ($amount <= BILLING_TOLERANCE) {
        $res['error'] = 'Enter the amount received.';
        return $res;
    }
    if (mb_strlen($reference) < 3 || mb_strlen($reference) > 190) {
        $res['error'] = 'A payment reference of 3 to 190 characters is required (receipt, transfer or cheque number).';
        return $res;
    }
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $doc = billing_doc_row($pdo, $docId, true);
        $fail = '';
        if (!$doc || $doc['kind'] !== 'invoice') {
            $fail = 'That invoice no longer exists.';
        } elseif (!in_array($doc['status'], ['draft', 'sent'], true)) {
            $fail = 'This invoice is ' . $doc['status'] . '; no payment can be recorded against it.';
        } elseif ($doc['tenant_id'] === null) {
            $fail = 'Link this invoice to a business before recording a payment (choose the business on the invoice and save).';
        }
        $tenant = $fail === '' ? tenant_find($pdo, (int)$doc['tenant_id'], true) : null;
        if ($fail === '' && !$tenant) {
            $fail = 'The business on this invoice no longer exists.';
        }
        $balance = $doc ? billing_balance($doc) : 0.0;
        if ($fail === '' && $amount - $balance > BILLING_TOLERANCE) {
            $fail = 'The amount is more than the balance due (' . billing_money($balance, (string)$doc['currency']) . ').';
        }
        if ($fail !== '') {
            if ($own) {
                $pdo->rollBack();
            }
            $res['error'] = $fail;
            return $res;
        }
        $newPaid = round((float)$doc['amount_paid'] + $amount, 2);
        $left = round((float)$doc['total'] - $newPaid, 2);
        $full = $left <= BILLING_TOLERANCE;
        $status = $full ? 'paid' : 'sent';
        $months = 0;
        $pBefore = (string)$tenant['paid_until'];
        $pAfter = $pBefore;
        if ($full && (int)$doc['extend_months'] > 0) {
            $months = (int)$doc['extend_months'];
            $pAfter = status_add_months(max(status_today(), $pBefore), $months);
            $pdo->prepare('UPDATE cp_tenants SET paid_until = ? WHERE id = ?')->execute([$pAfter, (int)$tenant['id']]);
        }
        // Times from the PHP clock (the database clock differs on the host). A draft that takes money counts as sent.
        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE cp_documents SET amount_paid = ?, status = ?, sent_at = COALESCE(sent_at, ?) WHERE id = ?')
            ->execute([number_format($newPaid, 2, '.', ''), $status, $now, $docId]);
        $pdo->prepare(
            'INSERT INTO cp_payments (tenant_id, document_id, admin_id, amount, currency, months, reference, method, paid_until_before, paid_until_after, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([(int)$tenant['id'], $docId, $adminId ?: null, number_format($amount, 2, '.', ''), $doc['currency'], $months,
            $reference, $method, $pBefore, $pAfter, $now]);
        $pid = (int)$pdo->lastInsertId();
        $docAfter = billing_doc_row($pdo, $docId);
        $docAfter['_payment'] = ['id' => $pid, 'amount' => number_format($amount, 2, '.', ''), 'currency' => $doc['currency'],
            'method' => $method, 'reference' => $reference, 'months' => $months, 'paid_until_before' => $pBefore, 'paid_until_after' => $pAfter];
        audit_log($pdo, $adminId, 'invoice_payment', (int)$tenant['id'], $doc, $docAfter);
        if ($months > 0) {
            $tAfter = tenant_find($pdo, (int)$tenant['id']);
            $tAfter['_payment'] = $docAfter['_payment'] + ['invoice' => $doc['number']];
            audit_log($pdo, $adminId, 'payment_recorded', (int)$tenant['id'], $tenant, $tAfter);
        }
        if ($own) {
            $pdo->commit();
        }
        return ['ok' => true, 'error' => '', 'payment_id' => $pid, 'paid_in_full' => $full];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function billing_payment(PDO $pdo, int $paymentId): ?array
{
    $st = $pdo->prepare('SELECT * FROM cp_payments WHERE id = ?');
    $st->execute([$paymentId]);
    $r = $st->fetch();
    return $r ?: null;
}

function billing_doc_payments(PDO $pdo, int $docId): array
{
    $st = $pdo->prepare('SELECT * FROM cp_payments WHERE document_id = ? ORDER BY id');
    $st->execute([$docId]);
    return $st->fetchAll();
}

// Only an unpaid invoice (nothing received) that is not already void. Returns '' or an error.
function billing_void(PDO $pdo, int $docId, int $adminId): string
{
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $doc = billing_doc_row($pdo, $docId, true);
        $err = '';
        if (!$doc || $doc['kind'] !== 'invoice') {
            $err = 'That invoice no longer exists.';
        } elseif ($doc['status'] === 'void') {
            $err = 'This invoice is already void.';
        } elseif ($doc['status'] === 'paid' || (float)$doc['amount_paid'] > BILLING_TOLERANCE) {
            $err = 'An invoice with money received against it cannot be voided.';
        }
        if ($err !== '') {
            if ($own) {
                $pdo->rollBack();
            }
            return $err;
        }
        $pdo->prepare('UPDATE cp_documents SET status = \'void\' WHERE id = ?')->execute([$docId]);
        audit_log($pdo, $adminId, 'invoice_void', $doc['tenant_id'] !== null ? (int)$doc['tenant_id'] : null, $doc, billing_doc_row($pdo, $docId));
        if ($own) {
            $pdo->commit();
        }
        return '';
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Manual status changes that send nothing: quote accepted / declined / expired / back to sent, and "marked as sent"
 * for an invoice or quote handed over outside this panel. Returns '' or an error.
 */
function billing_set_status(PDO $pdo, int $docId, string $status, int $adminId): string
{
    $allowed = [
        'invoice' => ['sent' => ['draft']],
        'quote' => ['sent' => ['draft'], 'accepted' => ['draft', 'sent', 'declined', 'expired'],
            'declined' => ['draft', 'sent', 'accepted', 'expired'], 'expired' => ['draft', 'sent']],
    ];
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $doc = billing_doc_row($pdo, $docId, true);
        $err = '';
        if (!$doc) {
            $err = 'That document no longer exists.';
        } elseif (!in_array($doc['status'], $allowed[$doc['kind']][$status] ?? [], true)) {
            $err = 'A ' . strtolower(billing_kind_label($doc['kind'])) . ' that is ' . $doc['status'] . ' cannot be marked ' . $status . '.';
        }
        if ($err !== '') {
            if ($own) {
                $pdo->rollBack();
            }
            return $err;
        }
        // PHP clock, not the database clock (they differ on the host).
        $pdo->prepare('UPDATE cp_documents SET status = ?, sent_at = COALESCE(sent_at, ?) WHERE id = ?')
            ->execute([$status, $status === 'sent' ? date('Y-m-d H:i:s') : null, $docId]);
        audit_log($pdo, $adminId, $doc['kind'] . '_marked_' . $status, $doc['tenant_id'] !== null ? (int)$doc['tenant_id'] : null,
            $doc, billing_doc_row($pdo, $docId));
        if ($own) {
            $pdo->commit();
        }
        return '';
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Copies a quote's customer and lines into a new draft invoice; the quote becomes converted. Returns the invoice id.
 * Throws InvalidArgumentException if the quote cannot be converted.
 */
function billing_quote_to_invoice(PDO $pdo, int $quoteId, int $adminId): int
{
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $q = billing_doc_row($pdo, $quoteId, true);
        if (!$q || $q['kind'] !== 'quote') {
            throw new InvalidArgumentException('That quotation no longer exists.');
        }
        if ($q['status'] === 'converted') {
            throw new InvalidArgumentException('This quotation has already been turned into an invoice.');
        }
        $today = status_today();
        $dueDays = (int)billing_setting('invoice_due_days');
        $doc = [
            'id' => 0, 'kind' => 'invoice', 'tenant_id' => $q['tenant_id'], 'customer_name' => $q['customer_name'],
            'customer_contact' => (string)$q['customer_contact'], 'customer_email' => (string)$q['customer_email'],
            'customer_address' => (string)$q['customer_address'], 'issue_date' => $today,
            'due_date' => status_add_days($today, $dueDays), 'currency' => $q['currency'],
            'salesperson' => (string)$q['salesperson'], 'notes' => (string)$q['notes'], 'discount' => (float)$q['discount'],
            'tax_rate' => (float)$q['tax_rate'], 'extend_months' => $q['tenant_id'] !== null ? (int)$q['extend_months'] : 0,
            'source_id' => $quoteId,
        ];
        $items = [];
        foreach (billing_doc_items($pdo, $quoteId) as $it) {
            $items[] = ['qty' => (float)$it['qty'], 'item' => $it['item'], 'description' => (string)$it['description'], 'unit_price' => (float)$it['unit_price']];
        }
        $newId = billing_doc_save($pdo, $doc, $items, $adminId);
        $pdo->prepare('UPDATE cp_documents SET status = \'converted\' WHERE id = ?')->execute([$quoteId]);
        audit_log($pdo, $adminId, 'quote_converted', $q['tenant_id'] !== null ? (int)$q['tenant_id'] : null, $q,
            billing_doc_row($pdo, $quoteId) + ['_invoice_id' => $newId]);
        if ($own) {
            $pdo->commit();
        }
        return $newId;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// Sent invoices of a business with something still to pay (draft, paid and void never count), oldest due first.
function billing_open_invoices(PDO $pdo, int $tenantId, ?string $today = null): array
{
    $st = $pdo->prepare(
        "SELECT * FROM cp_documents WHERE tenant_id = ? AND kind = 'invoice' AND status = 'sent' AND total - amount_paid > ?
         ORDER BY due_date IS NULL, due_date, id"
    );
    $st->execute([$tenantId, BILLING_TOLERANCE]);
    $out = [];
    foreach ($st->fetchAll() as $d) {
        $out[] = billing_doc_decorate($d, $today);
    }
    return $out;
}

function billing_tenant_summary(PDO $pdo, int $tenantId): array
{
    $tenant = tenant_find($pdo, $tenantId);
    $open = billing_open_invoices($pdo, $tenantId);
    // never add different currencies together: one total per currency; the business's own currency is the main one
    $by = [];
    foreach ($open as $d) {
        $c = (string)$d['currency'];
        $by[$c] = $by[$c] ?? ['owed' => 0.0, 'overdue' => 0.0];
        $by[$c]['owed'] = round($by[$c]['owed'] + $d['balance'], 2);
        if ($d['is_overdue']) {
            $by[$c]['overdue'] = round($by[$c]['overdue'] + $d['balance'], 2);
        }
    }
    $home = $tenant ? (string)$tenant['currency'] : billing_setting('default_currency');
    $currency = isset($by[$home]) || !$by ? $home : (string)array_key_first($by);
    $main = $by[$currency] ?? ['owed' => 0.0, 'overdue' => 0.0];
    unset($by[$currency]);
    $st = $pdo->prepare('SELECT * FROM cp_payments WHERE tenant_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$tenantId]);
    $last = $st->fetch();
    return ['owed' => $main['owed'], 'overdue' => $main['overdue'], 'currency' => $currency, 'other' => $by, 'open' => $open, 'last_payment' => $last ?: null];
}

// Every business that owes something, most overdue first.
function billing_owed_all(PDO $pdo): array
{
    $today = status_today();
    $st = $pdo->prepare(
        "SELECT t.id, t.name, t.billing_email, d.currency,
                SUM(d.total - d.amount_paid) AS owed,
                SUM(CASE WHEN d.due_date IS NOT NULL AND d.due_date < ? THEN d.total - d.amount_paid ELSE 0 END) AS overdue,
                MIN(d.due_date) AS oldest_due
         FROM cp_documents d JOIN cp_tenants t ON t.id = d.tenant_id
         WHERE d.kind = 'invoice' AND d.status = 'sent' AND d.total - d.amount_paid > ?
         GROUP BY t.id, t.name, t.billing_email, d.currency
         ORDER BY overdue DESC, oldest_due, t.name"
    );
    $st->execute([$today, BILLING_TOLERANCE]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'id' => (int)$r['id'], 'name' => (string)$r['name'], 'currency' => (string)$r['currency'],
            'owed' => round((float)$r['owed'], 2), 'overdue' => round((float)$r['overdue'], 2),
            'oldest_due' => $r['oldest_due'], 'billing_email' => (string)($r['billing_email'] ?? ''),
        ];
    }
    return $out;
}

/**
 * Account statement for a business: invoices (sent or paid; drafts and void never count) are debits on their date,
 * payments recorded against those invoices are credits on the day they were entered. Licence payments recorded on the
 * business page without an invoice are not part of the statement. $from / $to default to the first activity and today.
 */
function billing_statement(PDO $pdo, int $tenantId, ?string $from = null, ?string $to = null, ?string $currency = null): array
{
    $tenant = tenant_find($pdo, $tenantId);
    $to = ($to !== null && tenant_valid_date($to)) ? $to : status_today();
    $st = $pdo->prepare(
        "SELECT * FROM cp_documents WHERE tenant_id = ? AND kind = 'invoice' AND status IN ('sent','paid') ORDER BY issue_date, id"
    );
    $st->execute([$tenantId]);
    $all = $st->fetchAll();
    // one statement = one currency (amounts in different currencies are never added together)
    $currencies = array_values(array_unique(array_map(static fn($d) => (string)$d['currency'], $all)));
    $home = $tenant ? (string)$tenant['currency'] : billing_setting('default_currency');
    if ($currency === null || !in_array($currency, $currencies, true)) {
        $currency = in_array($home, $currencies, true) || !$currencies ? $home : $currencies[0];
    }
    $invoices = array_values(array_filter($all, static fn($d) => (string)$d['currency'] === $currency));
    $byId = [];
    foreach ($invoices as $d) {
        $byId[(int)$d['id']] = $d;
    }
    $ps = $pdo->prepare('SELECT * FROM cp_payments WHERE tenant_id = ? AND document_id IS NOT NULL ORDER BY created_at, id');
    $ps->execute([$tenantId]);
    $events = [];
    foreach ($invoices as $d) {
        $first = billing_doc_items($pdo, (int)$d['id']);
        $desc = 'Invoice ' . $d['number'] . ($first ? ': ' . $first[0]['item'] . (count($first) > 1 ? ' and ' . (count($first) - 1) . ' more' : '') : '');
        $events[] = ['date' => (string)$d['issue_date'], 'order' => 0, 'id' => (int)$d['id'], 'type' => 'invoice', 'ref' => (string)$d['number'],
            'description' => $desc, 'debit' => round((float)$d['total'], 2), 'credit' => 0.0];
    }
    $methods = billing_payment_methods();
    foreach ($ps->fetchAll() as $p) {
        $inv = $byId[(int)$p['document_id']] ?? null;
        if (!$inv) {
            continue; // payment against an invoice that is no longer counted
        }
        $events[] = ['date' => substr((string)$p['created_at'], 0, 10), 'order' => 1, 'id' => (int)$p['id'], 'type' => 'payment',
            'ref' => (string)$p['reference'],
            'description' => 'Payment for invoice ' . $inv['number'] . (isset($methods[(string)$p['method']]) ? ' (' . $methods[(string)$p['method']] . ')' : ''),
            'debit' => 0.0, 'credit' => round((float)$p['amount'], 2)];
    }
    usort($events, static function ($a, $b) {
        return [$a['date'], $a['order'], $a['id']] <=> [$b['date'], $b['order'], $b['id']];
    });
    if ($from === null || !tenant_valid_date($from)) {
        $from = $events ? $events[0]['date'] : $to;
    }
    $opening = 0.0;
    $bal = 0.0;
    $lines = [];
    foreach ($events as $ev) {
        if ($ev['date'] > $to) {
            break;
        }
        if ($ev['date'] < $from) {
            $opening = round($opening + $ev['debit'] - $ev['credit'], 2);
            $bal = $opening;
            continue;
        }
        $bal = round($bal + $ev['debit'] - $ev['credit'], 2);
        $lines[] = ['date' => $ev['date'], 'type' => $ev['type'], 'ref' => $ev['ref'], 'description' => $ev['description'],
            'debit' => $ev['debit'], 'credit' => $ev['credit'], 'balance' => $bal];
    }
    $open = array_values(array_filter(billing_open_invoices($pdo, $tenantId), static fn($d) => (string)$d['currency'] === $currency));
    return ['tenant' => $tenant, 'from' => $from, 'to' => $to, 'lines' => $lines, 'opening' => $opening, 'closing' => $bal,
        'currency' => $currency, 'currencies' => $currencies, 'open_invoices' => $open];
}

/**
 * Sent invoices with a balance that are due a reminder today under reminder_days, not already reminded today and not
 * first sent today. Each row is the decorated document plus 'days' = today minus due date (negative = before due,
 * 0 = on the due date, positive = days overdue).
 */
function billing_due_reminders(PDO $pdo, string $today): array
{
    $setting = billing_setting('reminder_days');
    if (!billing_reminder_offsets($setting)) {
        return [];
    }
    $st = $pdo->prepare(
        "SELECT * FROM cp_documents WHERE kind = 'invoice' AND status = 'sent' AND due_date IS NOT NULL AND total - amount_paid > ?
         ORDER BY due_date, id"
    );
    $st->execute([BILLING_TOLERANCE]);
    $out = [];
    foreach ($st->fetchAll() as $d) {
        if (!empty($d['sent_at']) && substr((string)$d['sent_at'], 0, 10) === $today) {
            continue;
        }
        $days = billing_reminder_match((string)$d['due_date'], $today, $setting, $d['last_reminder_at'] !== null ? (string)$d['last_reminder_at'] : null);
        if ($days === null) {
            continue;
        }
        $d = billing_doc_decorate($d, $today);
        $d['days'] = $days;
        $out[] = $d;
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------------------------
// Saved items
// ---------------------------------------------------------------------------------------------------------------

function billing_catalog(PDO $pdo, bool $activeOnly = true): array
{
    return $pdo->query('SELECT * FROM cp_catalog_items' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY is_active DESC, sort, item')->fetchAll();
}

// First use: fills the saved items with the owner's usual lines. Does nothing once any item exists.
function billing_seed_catalog(PDO $pdo): void
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM cp_catalog_items')->fetchColumn() > 0) {
        return;
    }
    $rows = [
        ['Web Hosting | Billed Monthly', 'Web Hosting for sites', '100000.00', 1],
        ['.com Domain Registration (2 Years)', 'Domain Registration (Client renews after 2 Years) for <domain>', '134000.00', 2],
        ['Service Fee', '1 Time Service Fee', '100000.00', 3],
        ['PMS licence | Monthly', 'Hotel management system licence', '0.00', 4],
    ];
    $st = $pdo->prepare('INSERT INTO cp_catalog_items (item, description, unit_price, currency, is_active, sort) VALUES (?, ?, ?, \'MWK\', 1, ?)');
    foreach ($rows as $r) {
        $st->execute($r);
    }
}

// Pure. Returns [errors, clean] for a saved item.
function billing_catalog_validate(array $in): array
{
    $err = [];
    $g = static function ($k, int $max) use ($in): string {
        $v = isset($in[$k]) && is_string($in[$k]) ? trim(str_replace("\r\n", "\n", $in[$k])) : '';
        return mb_substr($v, 0, $max);
    };
    $c = ['item' => $g('item', 190), 'description' => $g('description', 2000), 'unit_price' => $g('unit_price', 20),
        'currency' => strtoupper($g('currency', 3)), 'sort' => $g('sort', 5), 'is_active' => ($in['is_active'] ?? '') === '1' ? 1 : 0];
    if ($c['item'] === '') {
        $err[] = 'The item name is required.';
    }
    if ($c['unit_price'] === '') {
        $c['unit_price'] = '0';
    }
    if (!preg_match('/^\d{1,11}(\.\d{1,2})?$/', $c['unit_price'])) {
        $err[] = 'Unit price must be an amount, for example 100000.';
    }
    if (!preg_match('/^[A-Z]{3}$/', $c['currency'])) {
        $err[] = 'Currency must be a 3-letter code such as MWK.';
    }
    $c['sort'] = ctype_digit($c['sort']) ? min(9999, (int)$c['sort']) : 0;
    return [$err, $c];
}

// Inserts ($id 0) or updates a saved item. Returns the id.
function billing_catalog_save(PDO $pdo, int $id, array $c, int $adminId): int
{
    if ($id > 0) {
        $b = $pdo->prepare('SELECT * FROM cp_catalog_items WHERE id = ?');
        $b->execute([$id]);
        $before = $b->fetch() ?: null;
        if (!$before) {
            return 0;
        }
        $pdo->prepare('UPDATE cp_catalog_items SET item = ?, description = ?, unit_price = ?, currency = ?, is_active = ?, sort = ? WHERE id = ?')
            ->execute([$c['item'], $c['description'] !== '' ? $c['description'] : null, $c['unit_price'], $c['currency'], $c['is_active'], $c['sort'], $id]);
    } else {
        $before = null;
        $pdo->prepare('INSERT INTO cp_catalog_items (item, description, unit_price, currency, is_active, sort) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$c['item'], $c['description'] !== '' ? $c['description'] : null, $c['unit_price'], $c['currency'], $c['is_active'], $c['sort']]);
        $id = (int)$pdo->lastInsertId();
    }
    $a = $pdo->prepare('SELECT * FROM cp_catalog_items WHERE id = ?');
    $a->execute([$id]);
    audit_log($pdo, $adminId, 'catalog_item_saved', null, $before, $a->fetch() ?: null);
    return $id;
}

function billing_email_log(PDO $pdo, ?int $docId, ?int $tenantId = null, int $limit = 50): array
{
    if ($docId !== null) {
        $st = $pdo->prepare('SELECT * FROM cp_email_log WHERE document_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit));
        $st->execute([$docId]);
    } else {
        $st = $pdo->prepare('SELECT * FROM cp_email_log WHERE tenant_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit));
        $st->execute([(int)$tenantId]);
    }
    return $st->fetchAll();
}

// Optional parts built by other agents. Loaded only when present so a page still works without them.
function billing_has_pdf(): bool
{
    if (is_file(APP_ROOT . '/app/pdf.php')) {
        require_once APP_ROOT . '/app/pdf.php';
    }
    return function_exists('pdf_document');
}

function billing_has_mail(): bool
{
    if (is_file(APP_ROOT . '/app/billing_mail.php')) {
        require_once APP_ROOT . '/app/billing_mail.php';
    }
    return function_exists('billing_send');
}

// Streams a PDF download and ends the request.
function billing_stream_pdf(string $bytes, string $filename): void
{
    $safe = preg_replace('/[^A-Za-z0-9 ._-]/', '', $filename);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store, private');
    echo $bytes;
    exit;
}
