<?php
// Clients (owner 2026-11-09, BILLING.md Part 2): who a document or payment belongs to. A company or an individual,
// hosting with us or not. A hosted business (cp_tenants) has exactly one client row (cp_clients.tenant_id, unique);
// the business's billing contact and its client row are kept in step when either is edited.
// Older documents / payments without client_id belong to the client of their tenant_id ("map on read").
// Nothing runs at include time.

function client_kinds(): array
{
    return ['company' => 'Company', 'individual' => 'Individual'];
}

/**
 * Pure. Validates a client form. Returns [errors, clean]. Only the known fields are kept; tenant_id is never taken
 * from a form (it is set by client_for_tenant / client_sync_from_tenant only). Emails are lower-case-deduplicated;
 * cc_emails is stored as "a@x.com, b@y.com".
 */
function client_validate(array $in, string $defaultCurrency = 'MWK'): array
{
    $err = [];
    $s = static function ($k, int $max) use ($in): string {
        $v = isset($in[$k]) && (is_string($in[$k]) || is_numeric($in[$k])) ? trim(str_replace("\r\n", "\n", (string)$in[$k])) : '';
        return mb_substr($v, 0, $max);
    };
    $c = [
        'kind' => ($in['kind'] ?? '') === 'individual' ? 'individual' : 'company',
        'name' => $s('name', 190),
        'contact_name' => $s('contact_name', 120),
        'email' => $s('email', 190),
        'phone' => $s('phone', 60),
        'address' => $s('address', 1000),
        'tax_number' => $s('tax_number', 60),
        'currency' => strtoupper($s('currency', 3)),
        'source' => $s('source', 40),
        'notes' => $s('notes', 2000),
        'is_active' => array_key_exists('is_active', $in) ? ((string)$in['is_active'] === '1' || $in['is_active'] === 1 || $in['is_active'] === true ? 1 : 0) : 1,
    ];
    if ($c['name'] === '') {
        $err[] = $c['kind'] === 'individual' ? 'The person\'s full name is required.' : 'The company name is required.';
    }
    if ($c['email'] !== '' && !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
        $err[] = 'Email is not a valid email address.';
    }
    $cc = [];
    foreach (preg_split('/[\s,;]+/', $s('cc_emails', 500)) as $addr) {
        if ($addr === '') {
            continue;
        }
        if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            $err[] = 'Copy-to email is not a valid email address: ' . $addr;
            continue;
        }
        $cc[strtolower($addr)] = $cc[strtolower($addr)] ?? $addr; // first spelling wins
    }
    $c['cc_emails'] = mb_substr(implode(', ', $cc), 0, 500);
    if ($c['currency'] === '') {
        $c['currency'] = strtoupper($defaultCurrency);
    }
    if (!preg_match('/^[A-Z]{3}$/', $c['currency'])) {
        $err[] = 'Currency must be a 3-letter code such as MWK.';
    }
    if ($c['phone'] !== '' && !preg_match('/^[0-9+() .\/-]{3,60}$/', $c['phone'])) {
        $err[] = 'Phone may use digits, spaces and + ( ) - . / only.';
    }
    return [$err, $c];
}

function client_find(PDO $pdo, int $id, bool $forUpdate = false): ?array
{
    if ($id <= 0) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM cp_clients WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

function client_by_tenant(PDO $pdo, int $tenantId): ?array
{
    $st = $pdo->prepare('SELECT * FROM cp_clients WHERE tenant_id = ?');
    $st->execute([$tenantId]);
    $r = $st->fetch();
    return $r ?: null;
}

// Pure. The client fields that mirror a business's billing contact.
function client_fields_from_tenant(array $t): array
{
    $n = static fn($v) => ($v ?? '') === '' ? null : (string)$v;
    return ['name' => (string)$t['name'], 'contact_name' => $n($t['contact_name'] ?? null), 'email' => $n($t['billing_email'] ?? null),
        'cc_emails' => $n($t['cc_emails'] ?? null), 'phone' => $n($t['contact_phone'] ?? null), 'address' => $n($t['billing_address'] ?? null)];
}

/**
 * The client of a hosted business; creates it from the business when missing (audited; no welcome email: the
 * business is already a customer). Two requests creating it at once are safe (unique tenant_id: the loser re-reads).
 * Null when the business does not exist.
 */
function client_for_tenant(PDO $pdo, int $tenantId): ?array
{
    if ($tenantId <= 0) {
        return null;
    }
    $c = client_by_tenant($pdo, $tenantId);
    if ($c) {
        return $c;
    }
    $t = tenant_find($pdo, $tenantId);
    if (!$t) {
        return null;
    }
    $f = client_fields_from_tenant($t);
    try {
        $pdo->prepare('INSERT INTO cp_clients (kind, name, contact_name, email, cc_emails, phone, address, currency, tenant_id, source, is_active)
            VALUES (\'company\', ?, ?, ?, ?, ?, ?, ?, ?, \'tenant\', 1)')
            ->execute([$f['name'], $f['contact_name'], $f['email'], $f['cc_emails'], $f['phone'], $f['address'], (string)$t['currency'], $tenantId]);
        $c = client_find($pdo, (int)$pdo->lastInsertId());
        audit_log($pdo, null, 'client_created', $tenantId, null, $c);
        return $c;
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            return client_by_tenant($pdo, $tenantId);
        }
        throw $e;
    }
}

/**
 * Insert ($c['id'] empty) or update a client. Validates (InvalidArgumentException with the messages). For a hosted
 * business the name and billing contact are copied to the business too. A new client fires
 * automation_event('client_created') after the commit (only after the caller's commit when inside its transaction).
 * $c['tenant_id'] is honoured only on insert and only when set by code (never from a form). Returns the id.
 */
function client_save(PDO $pdo, array $c, int $adminId): int
{
    $id = isset($c['id']) ? (int)$c['id'] : 0;
    $tenantId = $id === 0 && !empty($c['tenant_id']) ? (int)$c['tenant_id'] : null;
    [$err, $v] = client_validate($c, billing_setting('default_currency'));
    if ($err) {
        throw new InvalidArgumentException(implode(' ', $err));
    }
    $nul = static fn(string $x): ?string => $x === '' ? null : $x;
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $vals = [$v['kind'], $v['name'], $nul($v['contact_name']), $nul($v['email']), $nul($v['cc_emails']), $nul($v['phone']), $nul($v['address']),
            $nul($v['tax_number']), $v['currency'], $nul($v['notes']), $v['is_active']];
        if ($id > 0) {
            $before = client_find($pdo, $id, true);
            if (!$before) {
                throw new InvalidArgumentException('That client no longer exists.');
            }
            $pdo->prepare('UPDATE cp_clients SET kind = ?, name = ?, contact_name = ?, email = ?, cc_emails = ?, phone = ?, address = ?, tax_number = ?,
                currency = ?, notes = ?, is_active = ? WHERE id = ?')->execute(array_merge($vals, [$id]));
            $after = client_find($pdo, $id);
            audit_log($pdo, $adminId ?: null, 'client_saved', $after['tenant_id'] !== null ? (int)$after['tenant_id'] : null, $before, $after);
            if ($after['tenant_id'] !== null) {
                client_sync_to_tenant($pdo, $after, $adminId);
            }
        } else {
            $pdo->prepare('INSERT INTO cp_clients (kind, name, contact_name, email, cc_emails, phone, address, tax_number, currency, notes, is_active, tenant_id, source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_merge($vals, [$tenantId, $nul($v['source']) ?? 'manual']));
            $id = (int)$pdo->lastInsertId();
            $after = client_find($pdo, $id);
            audit_log($pdo, $adminId ?: null, 'client_created', $tenantId, null, $after);
            billing_event('client_created', ['client_id' => $id]);
        }
        if ($own) {
            $pdo->commit();
            billing_events_flush($pdo);
        }
        return $id;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
            billing_events_clear();
        }
        throw $e;
    }
}

// Client -> business: copies name and billing contact to the business when they differ. Inside a transaction.
function client_sync_to_tenant(PDO $pdo, array $client, int $adminId): void
{
    $t = tenant_find($pdo, (int)$client['tenant_id'], true);
    if (!$t) {
        return;
    }
    $want = ['name' => (string)$client['name'], 'contact_name' => $client['contact_name'], 'billing_email' => $client['email'],
        'cc_emails' => $client['cc_emails'], 'contact_phone' => $client['phone'], 'billing_address' => $client['address']];
    $diff = false;
    foreach ($want as $k => $v) {
        if ((string)($t[$k] ?? '') !== (string)($v ?? '')) {
            $diff = true;
        }
    }
    if (!$diff) {
        return;
    }
    $pdo->prepare('UPDATE cp_tenants SET name = ?, contact_name = ?, billing_email = ?, cc_emails = ?, contact_phone = ?, billing_address = ? WHERE id = ?')
        ->execute([$want['name'], $want['contact_name'], $want['billing_email'], $want['cc_emails'], $want['contact_phone'], $want['billing_address'], (int)$t['id']]);
    audit_log($pdo, $adminId ?: null, 'tenant_contact_synced', (int)$t['id'], $t, tenant_find($pdo, (int)$t['id']));
}

/**
 * Business -> client: after a business is added or saved, its client row gets the same name and billing contact
 * (created when missing). Inside the caller's transaction when there is one. Returns the client id.
 */
function client_sync_from_tenant(PDO $pdo, int $tenantId, int $adminId): int
{
    $t = tenant_find($pdo, $tenantId);
    if (!$t) {
        return 0;
    }
    $c = client_by_tenant($pdo, $tenantId);
    if (!$c) {
        $c = client_for_tenant($pdo, $tenantId);
        return $c ? (int)$c['id'] : 0;
    }
    $f = client_fields_from_tenant($t);
    $diff = false;
    foreach ($f as $k => $v) {
        if ((string)($c[$k] ?? '') !== (string)($v ?? '')) {
            $diff = true;
        }
    }
    if ($diff) {
        $pdo->prepare('UPDATE cp_clients SET name = ?, contact_name = ?, email = ?, cc_emails = ?, phone = ?, address = ? WHERE id = ?')
            ->execute([$f['name'], $f['contact_name'], $f['email'], $f['cc_emails'], $f['phone'], $f['address'], (int)$c['id']]);
        audit_log($pdo, $adminId ?: null, 'client_synced', $tenantId, $c, client_find($pdo, (int)$c['id']));
    }
    return (int)$c['id'];
}

/**
 * SQL condition for "rows of this client" on cp_documents / cp_payments / cp_email_log (older rows carry only the
 * business). Returns [sql, args]; $alias e.g. 'd.'.
 */
function client_where(array $client, string $alias = ''): array
{
    return ['(' . $alias . 'client_id = ? OR (' . $alias . 'client_id IS NULL AND ' . $alias . 'tenant_id = ?))',
        [(int)$client['id'], $client['tenant_id'] !== null ? (int)$client['tenant_id'] : 0]];
}

// Sent invoices of a client with something still to pay, oldest due first, decorated (balance, is_overdue).
function client_open_invoices(PDO $pdo, array $client, ?string $today = null): array
{
    [$w, $a] = client_where($client);
    $st = $pdo->prepare("SELECT * FROM cp_documents WHERE $w AND kind = 'invoice' AND status = 'sent' AND total - amount_paid > ?
        ORDER BY due_date IS NULL, due_date, id");
    $st->execute(array_merge($a, [BILLING_TOLERANCE]));
    $out = [];
    foreach ($st->fetchAll() as $d) {
        $out[] = billing_doc_decorate($d, $today);
    }
    return $out;
}

/**
 * What a client owes, per currency (never added across currencies). Contract keys: owed, overdue, currency (the main
 * one: the client's own currency when it has invoices in it), other [currency => owed, overdue], open [docs],
 * last_payment. Plus 'totals' [currency => invoiced (sent + paid invoices), received (payments), owed, overdue].
 */
function client_summary(PDO $pdo, int $clientId): array
{
    $client = client_find($pdo, $clientId);
    $home = $client ? (string)$client['currency'] : billing_setting('default_currency');
    if (!$client) {
        return ['owed' => 0.0, 'overdue' => 0.0, 'currency' => $home, 'other' => [], 'open' => [], 'last_payment' => null, 'totals' => []];
    }
    $open = client_open_invoices($pdo, $client);
    $rows = [];
    foreach ($open as $d) {
        $rows[] = ['currency' => $d['currency'], 'owed' => $d['balance'], 'overdue' => $d['is_overdue'] ? $d['balance'] : 0];
    }
    [$currency, $main, $other] = billing_pick_currency(billing_sum_by_currency($rows, ['owed', 'overdue']), $home, ['owed', 'overdue']);
    [$w, $a] = client_where($client);
    $st = $pdo->prepare("SELECT * FROM cp_payments WHERE $w ORDER BY id DESC LIMIT 1");
    $st->execute($a);
    $last = $st->fetch();
    $inv = $pdo->prepare("SELECT currency, SUM(total) AS invoiced FROM cp_documents WHERE $w AND kind = 'invoice' AND status IN ('sent','paid') GROUP BY currency");
    $inv->execute($a);
    $rec = $pdo->prepare("SELECT currency, SUM(amount) AS received FROM cp_payments WHERE $w GROUP BY currency");
    $rec->execute($a);
    $totals = billing_sum_by_currency(array_merge($inv->fetchAll(), $rec->fetchAll(), $rows), ['invoiced', 'received', 'owed', 'overdue']);
    return ['owed' => (float)$main['owed'], 'overdue' => (float)$main['overdue'], 'currency' => $currency, 'other' => $other,
        'open' => $open, 'last_payment' => $last ?: null, 'totals' => $totals];
}

// Invoices and/or quotations of a client, newest first, decorated.
function client_documents(PDO $pdo, int $clientId, ?string $kind = null, int $limit = 200): array
{
    $client = client_find($pdo, $clientId);
    if (!$client) {
        return [];
    }
    [$w, $a] = client_where($client);
    if ($kind !== null) {
        $w .= ' AND kind = ?';
        $a[] = $kind === 'quote' ? 'quote' : 'invoice';
    }
    $st = $pdo->prepare("SELECT * FROM cp_documents WHERE $w ORDER BY issue_date DESC, id DESC LIMIT " . max(1, min(1000, $limit)));
    $st->execute($a);
    return array_map(static fn($d) => billing_doc_decorate($d), $st->fetchAll());
}

// Payments of a client (against invoices, and licence payments of its business), newest first, with the invoice number.
function client_payments(PDO $pdo, int $clientId, int $limit = 200): array
{
    $client = client_find($pdo, $clientId);
    if (!$client) {
        return [];
    }
    [$w, $a] = client_where($client, 'p.');
    $st = $pdo->prepare("SELECT p.*, d.number AS doc_number FROM cp_payments p LEFT JOIN cp_documents d ON d.id = p.document_id
        WHERE $w ORDER BY p.id DESC LIMIT " . max(1, min(1000, $limit)));
    $st->execute($a);
    return $st->fetchAll();
}

// Pure. A business-shaped array for a client (the statement PDF prints name, contact_name and billing_address).
function client_as_party(array $client): array
{
    return ['id' => null, 'name' => (string)$client['name'], 'contact_name' => (string)($client['contact_name'] ?? ''),
        'billing_email' => (string)($client['email'] ?? ''), 'cc_emails' => (string)($client['cc_emails'] ?? ''),
        'billing_address' => (string)($client['address'] ?? ''), 'currency' => (string)$client['currency']];
}

/**
 * Statement of one client in ONE currency (same shape as billing_statement, plus 'client'). 'tenant' is the hosted
 * business, or for any other client a business-shaped copy of the client (id null) so the PDF prints the customer.
 * $currency null = the client's own currency when it has invoices in it, else the first it has.
 */
function client_statement(PDO $pdo, int $clientId, ?string $from = null, ?string $to = null, ?string $currency = null): array
{
    $client = client_find($pdo, $clientId);
    $to = ($to !== null && tenant_valid_date($to)) ? $to : status_today();
    if (!$client) {
        return ['client' => null, 'tenant' => null, 'from' => $from ?? $to, 'to' => $to, 'lines' => [], 'opening' => 0.0, 'closing' => 0.0,
            'currency' => billing_setting('default_currency'), 'currencies' => [], 'open_invoices' => []];
    }
    $tenant = $client['tenant_id'] !== null ? tenant_find($pdo, (int)$client['tenant_id']) : null;
    [$w, $a] = client_where($client);
    $st = $pdo->prepare("SELECT * FROM cp_documents WHERE $w AND kind = 'invoice' AND status IN ('sent','paid') ORDER BY issue_date, id");
    $st->execute($a);
    $all = $st->fetchAll();
    $currencies = array_values(array_unique(array_map(static fn($d) => (string)$d['currency'], $all)));
    sort($currencies);
    $home = (string)$client['currency'];
    if ($currency === null || !in_array($currency, $currencies, true)) {
        $currency = in_array($home, $currencies, true) || !$currencies ? $home : $currencies[0];
    }
    $invoices = [];
    foreach ($all as $d) {
        if ((string)$d['currency'] !== $currency) {
            continue;
        }
        $items = billing_doc_items($pdo, (int)$d['id']);
        $d['description'] = 'Invoice ' . $d['number'] . ($items ? ': ' . $items[0]['item'] . (count($items) > 1 ? ' and ' . (count($items) - 1) . ' more' : '') : '');
        $invoices[] = $d;
    }
    $ps = $pdo->prepare("SELECT * FROM cp_payments WHERE $w AND document_id IS NOT NULL ORDER BY created_at, id");
    $ps->execute($a);
    $b = billing_statement_build($invoices, $ps->fetchAll(), $from, $to);
    $open = array_values(array_filter(client_open_invoices($pdo, $client), static fn($d) => (string)$d['currency'] === $currency));
    return ['client' => $client, 'tenant' => $tenant ?: client_as_party($client), 'from' => $b['from'], 'to' => $b['to'], 'lines' => $b['lines'],
        'opening' => $b['opening'], 'closing' => $b['closing'], 'currency' => $currency, 'currencies' => $currencies, 'open_invoices' => $open];
}

// Pure. Escapes a search term for LIKE (\ % _ are literal).
function client_like(string $q): string
{
    return '%' . addcslashes($q, '\\%_') . '%';
}

/**
 * Client list with search: $q matches name, contact, email, phone (LIKE, escaped); $f: kind (company|individual),
 * hosted ('1' hosted / '0' not), active ('1' / '0' / '' any). Each row has 'owed' [currency => amount] on sent invoices.
 */
function client_search(PDO $pdo, string $q, array $f = [], int $limit = 300): array
{
    $where = ['1=1'];
    $args = [];
    $q = trim(mb_substr($q, 0, 100));
    if ($q !== '') {
        $where[] = '(c.name LIKE ? OR c.contact_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)';
        $like = client_like($q);
        array_push($args, $like, $like, $like, $like);
    }
    if (isset($f['kind']) && isset(client_kinds()[$f['kind']])) {
        $where[] = 'c.kind = ?';
        $args[] = $f['kind'];
    }
    if (($f['hosted'] ?? '') === '1') {
        $where[] = 'c.tenant_id IS NOT NULL';
    } elseif (($f['hosted'] ?? '') === '0') {
        $where[] = 'c.tenant_id IS NULL';
    }
    if (($f['active'] ?? '') === '1' || ($f['active'] ?? '') === '0') {
        $where[] = 'c.is_active = ?';
        $args[] = (int)$f['active'];
    }
    $st = $pdo->prepare('SELECT c.*, t.slug AS tenant_slug FROM cp_clients c LEFT JOIN cp_tenants t ON t.id = c.tenant_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY c.is_active DESC, c.name LIMIT ' . max(1, min(1000, $limit)));
    $st->execute($args);
    $rows = $st->fetchAll();
    $owed = [];
    foreach (billing_owed_all($pdo) as $o) {
        $owed[$o['client_id']][$o['currency']] = ['owed' => $o['owed'], 'overdue' => $o['overdue']];
    }
    foreach ($rows as &$r) {
        $r['owed'] = $owed[(int)$r['id']] ?? [];
    }
    unset($r);
    return $rows;
}

// Active clients for a picker (plus $includeId even when inactive), by name.
function client_options(PDO $pdo, int $includeId = 0): array
{
    $st = $pdo->prepare('SELECT * FROM cp_clients WHERE is_active = 1 OR id = ? ORDER BY name');
    $st->execute([$includeId]);
    return $st->fetchAll();
}

/**
 * Pure. Who an email about a document goes to: the client's email (+ its copy-to list); the document's printed
 * customer email only when the client has none. Returns [to, cc] (arrays of addresses).
 */
function client_recipients(?array $client, ?array $doc = null): array
{
    $to = billing_parse_emails((string)($client['email'] ?? ''));
    if (!$to && $doc) {
        $to = billing_parse_emails((string)($doc['customer_email'] ?? ''));
    }
    $cc = array_values(array_filter(billing_parse_emails((string)($client['cc_emails'] ?? '')),
        static fn($e) => !in_array(strtolower($e), array_map('strtolower', $to), true)));
    return [$to, $cc];
}
