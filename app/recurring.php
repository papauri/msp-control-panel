<?php
// Recurring invoices (owner 2026-11-09, BILLING.md Part 2): a schedule per client that makes a draft invoice every
// N months. The daily run (scripts/billing-daily.php, agent AUTOMATION) calls recurring_due + recurring_run_one and
// then sends / queues / leaves the draft per the schedule's send_mode. Nothing runs at include time.

function recurring_send_modes(): array
{
    return ['auto' => 'Send automatically', 'approve' => 'Wait in the Outbox for approval', 'draft' => 'Leave as a draft'];
}

/**
 * Pure. Calendar-safe month step: the day is kept when the target month has it, otherwise the month's last day
 * (31 Jan + 1 = 28 Feb, or 29 Feb in a leap year; 30 Nov + 3 = 28/29 Feb). Never spills into the next month.
 */
function recurring_add_months(string $date, int $months): string
{
    return status_add_months(substr($date, 0, 10), $months);
}

// Pure. Is the schedule due on $today: active, next_date reached, and end_date (if any) not before next_date.
function recurring_is_due(array $r, string $today): bool
{
    return (int)($r['is_active'] ?? 0) === 1 && (string)$r['next_date'] <= $today
        && (empty($r['end_date']) || (string)$r['end_date'] >= (string)$r['next_date']);
}

/**
 * Pure. Validates a schedule form and its lines (billing_lines_validate). Returns [errors, clean]; clean['items'] is
 * the list stored in items_json. client_id must be a positive id (its existence is checked by recurring_save).
 */
function recurring_validate(array $in, array $lines, string $defaultCurrency = 'MWK'): array
{
    $err = [];
    $s = static function ($k, int $max) use ($in): string {
        $v = isset($in[$k]) && (is_string($in[$k]) || is_numeric($in[$k])) ? trim(str_replace("\r\n", "\n", (string)$in[$k])) : '';
        return mb_substr($v, 0, $max);
    };
    $r = [
        'client_id' => ctype_digit($s('client_id', 10)) ? (int)$s('client_id', 10) : 0,
        'title' => $s('title', 190),
        'interval_months' => $s('interval_months', 3),
        'next_date' => $s('next_date', 10),
        'end_date' => $s('end_date', 10),
        'currency' => strtoupper($s('currency', 3)),
        'due_days' => $s('due_days', 4),
        'extend_months' => $s('extend_months', 3),
        'notes' => $s('notes', 2000),
        'send_mode' => $s('send_mode', 10),
        'is_active' => array_key_exists('is_active', $in) ? ((string)$in['is_active'] === '1' ? 1 : 0) : 1,
    ];
    if ($r['client_id'] <= 0) {
        $err[] = 'Choose the client.';
    }
    if ($r['title'] === '') {
        $err[] = 'A title is required, for example "Web hosting" (it is shown in the email).';
    }
    if (!ctype_digit($r['interval_months']) || (int)$r['interval_months'] < 1 || (int)$r['interval_months'] > 36) {
        $err[] = 'Every how many months must be a whole number from 1 to 36.';
        $r['interval_months'] = '1';
    }
    $r['interval_months'] = (int)$r['interval_months'];
    if (!tenant_valid_date($r['next_date'])) {
        $err[] = 'Next invoice date must be a valid date.';
    }
    if ($r['end_date'] === '') {
        $r['end_date'] = null;
    } elseif (!tenant_valid_date($r['end_date'])) {
        $err[] = 'End date must be a valid date or empty.';
    } elseif (tenant_valid_date($r['next_date']) && $r['end_date'] < $r['next_date']) {
        $err[] = 'End date cannot be before the next invoice date.';
    }
    if ($r['currency'] === '') {
        $r['currency'] = strtoupper($defaultCurrency);
    }
    if (!preg_match('/^[A-Z]{3}$/', $r['currency'])) {
        $err[] = 'Currency must be a 3-letter code such as MWK.';
    }
    if ($r['due_days'] === '') {
        $r['due_days'] = '7';
    }
    if (!ctype_digit($r['due_days']) || (int)$r['due_days'] > 365) {
        $err[] = 'Due after must be a whole number of days from 0 to 365.';
        $r['due_days'] = '7';
    }
    $r['due_days'] = (int)$r['due_days'];
    if ($r['extend_months'] === '') {
        $r['extend_months'] = '0';
    }
    if (!ctype_digit($r['extend_months']) || (int)$r['extend_months'] > 60) {
        $err[] = 'Extend licence by must be a whole number of months from 0 to 60.';
        $r['extend_months'] = '0';
    }
    $r['extend_months'] = (int)$r['extend_months'];
    if (!isset(recurring_send_modes()[$r['send_mode']])) {
        $r['send_mode'] = 'approve';
    }
    [$lineErr, $items] = billing_lines_validate($lines);
    $err = array_merge($err, $lineErr);
    $t = billing_calc_totals($items, 0, 0);
    if ($t['total'] < -BILLING_TOLERANCE) {
        $err[] = 'The total cannot be below zero.';
    }
    $r['items'] = array_map(static fn($it) => ['qty' => $it['qty'], 'item' => $it['item'], 'description' => $it['description'],
        'unit_price' => $it['unit_price'], 'cost_price' => $it['cost_price'], 'catalog_item_id' => $it['catalog_item_id']], $items);
    return [$err, $r];
}

function recurring_find(PDO $pdo, int $id, bool $forUpdate = false): ?array
{
    $st = $pdo->prepare('SELECT * FROM cp_recurring WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

// Pure. The lines stored in items_json (bad JSON = no lines).
function recurring_items(array $r): array
{
    $items = json_decode((string)($r['items_json'] ?? ''), true);
    return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
}

// Schedules (with the client's name), optionally of one client; active first, soonest first.
function recurring_list(PDO $pdo, ?int $clientId = null): array
{
    $sql = 'SELECT r.*, c.name AS client_name FROM cp_recurring r JOIN cp_clients c ON c.id = r.client_id';
    $args = [];
    if ($clientId !== null) {
        $sql .= ' WHERE r.client_id = ?';
        $args[] = $clientId;
    }
    $st = $pdo->prepare($sql . ' ORDER BY r.is_active DESC, r.next_date, r.id');
    $st->execute($args);
    return $st->fetchAll();
}

/**
 * Insert ($r['id'] empty) or update a schedule from recurring_validate's clean array. The client must exist; tenant_id
 * follows the client (a licence extension needs a hosted business). Audited. Throws InvalidArgumentException.
 */
function recurring_save(PDO $pdo, array $r, int $adminId): int
{
    $id = isset($r['id']) ? (int)$r['id'] : 0;
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $client = client_find($pdo, (int)($r['client_id'] ?? 0));
        if (!$client) {
            throw new InvalidArgumentException('That client no longer exists.');
        }
        $tenantId = $client['tenant_id'] !== null ? (int)$client['tenant_id'] : null;
        if ((int)($r['extend_months'] ?? 0) > 0 && $tenantId === null) {
            throw new InvalidArgumentException('Extending a licence needs a hosted business: ' . $client['name'] . ' has no licence here. Set the months to 0.');
        }
        $items = $r['items'] ?? [];
        if (!$items) {
            throw new InvalidArgumentException('Add at least one line.');
        }
        $vals = [(int)$client['id'], $tenantId, (string)$r['title'], (int)$r['interval_months'], (string)$r['next_date'],
            !empty($r['end_date']) ? (string)$r['end_date'] : null, strtoupper((string)$r['currency']), (int)$r['due_days'], (int)$r['extend_months'],
            json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ($r['notes'] ?? '') !== '' ? (string)$r['notes'] : null,
            (string)$r['send_mode'], (int)$r['is_active']];
        $before = null;
        if ($id > 0) {
            $before = recurring_find($pdo, $id, true);
            if (!$before) {
                throw new InvalidArgumentException('That schedule no longer exists.');
            }
            $pdo->prepare('UPDATE cp_recurring SET client_id = ?, tenant_id = ?, title = ?, interval_months = ?, next_date = ?, end_date = ?, currency = ?,
                due_days = ?, extend_months = ?, items_json = ?, notes = ?, send_mode = ?, is_active = ? WHERE id = ?')->execute(array_merge($vals, [$id]));
        } else {
            $pdo->prepare('INSERT INTO cp_recurring (client_id, tenant_id, title, interval_months, next_date, end_date, currency, due_days, extend_months,
                items_json, notes, send_mode, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_merge($vals, [$adminId ?: null]));
            $id = (int)$pdo->lastInsertId();
        }
        audit_log($pdo, $adminId ?: null, 'recurring_saved', $tenantId, $before, recurring_find($pdo, $id));
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

// Active schedules with next_date on or before $today and end_date not passed, oldest first.
function recurring_due(PDO $pdo, string $today): array
{
    $st = $pdo->prepare('SELECT r.*, c.name AS client_name FROM cp_recurring r JOIN cp_clients c ON c.id = r.client_id
        WHERE r.is_active = 1 AND r.next_date <= ? AND (r.end_date IS NULL OR r.end_date >= r.next_date) ORDER BY r.next_date, r.id');
    $st->execute([$today]);
    return $st->fetchAll();
}

// Active schedules whose next invoice falls within $days from $today (overdue runs included), for the billing board.
function recurring_upcoming(PDO $pdo, string $today, int $days): array
{
    $st = $pdo->prepare('SELECT r.*, c.name AS client_name FROM cp_recurring r JOIN cp_clients c ON c.id = r.client_id
        WHERE r.is_active = 1 AND r.next_date <= ? AND (r.end_date IS NULL OR r.end_date >= r.next_date) ORDER BY r.next_date, r.id');
    $st->execute([status_add_days($today, $days)]);
    return $st->fetchAll();
}

/**
 * Makes one invoice (status draft) from a due schedule and moves next_date on by interval_months, calendar-safely,
 * in one transaction with the schedule row locked (a second run on the same day finds it no longer due). The invoice
 * is dated $today and due after the schedule's due_days. A schedule past its end_date is switched off.
 * $early (the owner's "make the next invoice now"): also runs a schedule whose next_date is still ahead.
 * Returns ['ok','document_id','send_mode','error','next_date'].
 */
function recurring_run_one(PDO $pdo, int $id, int $adminId, string $today, bool $early = false): array
{
    $res = ['ok' => false, 'document_id' => 0, 'send_mode' => '', 'error' => '', 'next_date' => null];
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $r = recurring_find($pdo, $id, true);
        $fail = '';
        if (!$r) {
            $fail = 'That schedule no longer exists.';
        } elseif (!recurring_is_due($r, $early ? max($today, (string)$r['next_date']) : $today)) {
            $fail = (int)$r['is_active'] !== 1 ? 'This schedule is paused.' : 'This schedule is not due yet (next invoice ' . billing_date((string)$r['next_date']) . ').';
        }
        $client = $fail === '' ? client_find($pdo, (int)$r['client_id']) : null;
        if ($fail === '' && !$client) {
            $fail = 'The client of this schedule no longer exists.';
        }
        $items = $fail === '' ? recurring_items($r) : [];
        if ($fail === '' && !$items) {
            $fail = 'This schedule has no lines.';
        }
        if ($fail !== '') {
            if ($own) {
                $pdo->rollBack();
            }
            $res['error'] = $fail;
            return $res;
        }
        $doc = [
            'id' => 0, 'kind' => 'invoice', 'client_id' => (int)$client['id'], 'customer_name' => (string)$client['name'],
            'customer_contact' => (string)($client['contact_name'] ?? ''), 'customer_email' => (string)($client['email'] ?? ''),
            'customer_address' => (string)($client['address'] ?? ''), 'issue_date' => $today,
            'due_date' => status_add_days($today, (int)$r['due_days']), 'currency' => (string)$r['currency'],
            'salesperson' => billing_setting('salesperson'), 'notes' => (string)($r['notes'] ?? ''), 'discount' => 0,
            'tax_rate' => (float)billing_setting('tax_rate'), 'extend_months' => (int)$r['extend_months'],
        ];
        $lines = [];
        foreach ($items as $it) {
            $lines[] = ['qty' => (float)($it['qty'] ?? 1), 'item' => (string)($it['item'] ?? ''), 'description' => (string)($it['description'] ?? ''),
                'unit_price' => (float)($it['unit_price'] ?? 0),
                'cost_price' => isset($it['cost_price']) && $it['cost_price'] !== '' && $it['cost_price'] !== null ? (float)$it['cost_price'] : null,
                'catalog_item_id' => !empty($it['catalog_item_id']) ? (int)$it['catalog_item_id'] : null];
        }
        $docId = billing_doc_save($pdo, $doc, $lines, $adminId);
        $next = recurring_add_months((string)$r['next_date'], (int)$r['interval_months']);
        $active = empty($r['end_date']) || $next <= (string)$r['end_date'] ? 1 : 0;
        $pdo->prepare('UPDATE cp_recurring SET next_date = ?, last_document_id = ?, last_run_at = ?, is_active = ? WHERE id = ?')
            ->execute([$next, $docId, date('Y-m-d H:i:s'), $active, $id]);
        audit_log($pdo, $adminId ?: null, 'recurring_run', $r['tenant_id'] !== null ? (int)$r['tenant_id'] : null, $r,
            recurring_find($pdo, $id) + ['_document_id' => $docId]);
        if ($own) {
            $pdo->commit();
            billing_events_flush($pdo);
        }
        return ['ok' => true, 'document_id' => $docId, 'send_mode' => (string)$r['send_mode'], 'error' => '', 'next_date' => $next];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
            billing_events_clear();
        }
        if ($e instanceof InvalidArgumentException) {
            $res['error'] = $e->getMessage();
            return $res;
        }
        throw $e;
    }
}
