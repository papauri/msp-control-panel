<?php
// Billing automations and the Outbox (BILLING.md Part 2, "Agent AUTOMATION"). Nothing runs at include time.
//
// Every automated email goes through cp_outbox, whatever the mode:
//   off     -> nothing is created.
//   approve -> a pending Outbox row; the owner sends or skips it on outbox.php.
//   auto    -> an Outbox row is created and sent straight away; a failure stays as a failed row the owner can retry.
// The row's dedupe_key is UNIQUE, so inserting it is an atomic claim: the same payment / invoice / reminder day can never be
// queued or sent twice, even by two overlapping runs. Sending a row first claims it with a conditional UPDATE.
// Auto sends never throw into the caller (errors are logged and kept on the row). MAIL_TEST_TO (app/mailer.php) still
// redirects every email while it is set.
//
// automation_modes(): kind => off|approve|auto        automation_event(PDO, event, data): void
// outbox_add(PDO, kind, refId, opts, reason, dedupe): int (0 = already there)
// outbox_decide(PDO, id, 'send'|'skip', adminId, message): ['ok','error','status']
// billing_daily(PDO, today, dryRun, deps): recurring invoices, then reminders, then a summary; one run at a time (lock file).

const AUTOMATION_MODES = ['off', 'approve', 'auto'];

/** Contract defaults for the automation settings (cp_settings keys). */
function automation_defaults(): array
{
    return ['auto_thank_payment' => 'auto', 'auto_thank_business' => 'auto', 'auto_welcome' => 'approve',
        'auto_quote_accepted' => 'auto', 'auto_reminders' => 'approve',
        // Part 3: free trials
        'auto_trial_started' => 'auto', 'auto_trial_ending' => 'auto', 'auto_trial_ended' => 'auto', 'auto_trial_converted' => 'auto'];
}

/** The trial email kinds (ref_id = the business, cp_tenants.id). */
function automation_trial_kinds(): array
{
    return ['trial_started', 'trial_ending', 'trial_ended', 'trial_converted'];
}

/** What each automation sends and when, in plain words (automations.php). */
function automation_catalog(): array
{
    return [
        'auto_thank_payment' => ['title' => 'Thank you for your payment', 'kind' => 'thank_you_payment',
            'what' => 'A warm thank-you with the receipt (PDF) attached, showing the amount, method, reference and what is left to pay.',
            'when' => 'Every time a payment is recorded, for any client.'],
        'auto_thank_business' => ['title' => 'Thank you for your business', 'kind' => 'thank_you_business',
            'what' => 'A thank-you for the business with the invoice marked PAID attached.',
            'when' => 'When a payment settles an invoice in full.'],
        'auto_welcome' => ['title' => 'Welcome', 'kind' => 'welcome',
            'what' => 'A welcome that explains how billing works (invoices by email, due days, reminders, receipts, statements) and how to pay.',
            'when' => 'When a new client is added.'],
        'auto_quote_accepted' => ['title' => 'Quotation accepted', 'kind' => 'quote_accepted',
            'what' => 'A confirmation and thank-you with the accepted quotation attached; the invoice follows separately.',
            'when' => 'When a quotation is marked accepted.'],
        'auto_reminders' => ['title' => 'Payment reminders', 'kind' => 'reminder',
            'what' => 'A polite reminder with the invoice attached (before the due date, on it, or overdue wording).',
            'when' => 'Each day the daily job runs, on the reminder days set in Settings (for example 3 days before, on the day, 7 and 14 days after).'],
        'auto_trial_started' => ['title' => 'Free trial started', 'kind' => 'trial_started',
            'what' => 'Welcome to the free trial: what is included, how long it lasts and who to call.',
            'when' => 'When a free trial is started for a business.'],
        'auto_trial_ending' => ['title' => 'Free trial ending', 'kind' => 'trial_ending',
            'what' => 'How many days are left, what happens at the end, and the offer to continue (monthly, or yearly with the annual discount).',
            'when' => 'Each day the daily job runs, on the trial reminder days set in Settings (for example 7, 3 and 1 days before the end).'],
        'auto_trial_ended' => ['title' => 'Free trial ended', 'kind' => 'trial_ended',
            'what' => 'What happens now (view-only, then the staff area pauses; data is kept), how to continue, and the payment details.',
            'when' => 'The day after a trial ends (caught up by the daily job for up to 3 days), once per trial.'],
        'auto_trial_converted' => ['title' => 'Thank you for choosing us', 'kind' => 'trial_converted',
            'what' => 'A thank-you when a trial becomes a paid plan, with the plan and how often it is billed.',
            'when' => 'When a trial is converted to a paid plan on the business page.'],
    ];
}

/**
 * Pure. The mode for a setting: off | approve | auto. Nothing stored -> the contract default. auto_reminders was a 0/1
 * switch in Part 1: '0' -> off, '1' -> approve (on, but nothing leaves without the owner seeing it first).
 */
function automation_mode_resolve(string $key, ?string $stored): string
{
    $defaults = automation_defaults();
    $default = $defaults[$key] ?? 'off';
    if ($stored === null) {
        return $default;
    }
    $v = strtolower(trim($stored));
    if (in_array($v, AUTOMATION_MODES, true)) {
        return $v;
    }
    if ($v === '0') {
        return 'off';
    }
    if ($v === '1') {
        return 'approve';
    }
    return $default;
}

/** key => off|approve|auto, from the stored settings (defaults when not set). */
function automation_modes(): array
{
    $stored = function_exists('billing_settings_cache') ? billing_settings_cache() : [];
    $out = [];
    foreach (automation_defaults() as $k => $d) {
        $out[$k] = automation_mode_resolve($k, array_key_exists($k, $stored) ? (string)$stored[$k] : null);
    }
    return $out;
}

/** Saves one automation's mode (cp_settings), audited. Returns '' or an error. */
function automation_mode_save(PDO $pdo, string $key, string $mode, int $adminId): string
{
    if (!array_key_exists($key, automation_defaults())) {
        return 'Unknown automation.';
    }
    if (!in_array($mode, AUTOMATION_MODES, true)) {
        return 'Choose off, ask me first, or send automatically.';
    }
    $before = automation_modes()[$key];
    if ($before === $mode) {
        return '';
    }
    $pdo->prepare('INSERT INTO cp_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([$key, $mode]);
    if (function_exists('billing_settings_cache')) {
        billing_settings_cache(null, true);
    }
    audit_log($pdo, $adminId > 0 ? $adminId : null, 'automation_mode_saved', null, [$key => $before], [$key => $mode]);
    return '';
}

/** Pure. The key that makes an automated email unique: "thank_you_payment:12", "reminder:7:2026-11-09". */
function automation_dedupe_key(string $kind, int $refId, string $day = ''): string
{
    $key = preg_replace('/[^a-z_]/', '', strtolower($kind)) . ':' . $refId;
    if ($kind === 'reminder') {
        $key .= ':' . ($day !== '' ? substr($day, 0, 10) : date('Y-m-d'));
    } elseif (in_array($kind, automation_trial_kinds(), true) && $day !== '') {
        $key .= ':' . substr($day, 0, 10); // per business + day (trial_ended: + the trial's end date)
    }
    return substr($key, 0, 120);
}

/**
 * Pure. What an event should produce: a list of ['kind','ref_id','mode','reason','dedupe'] (mode off included, so the
 * caller can report it). Events: payment_recorded {payment_id, document_id, paid_in_full}, quote_accepted {document_id},
 * client_created {client_id}.
 */
function automation_plan_event(string $event, array $data, array $modes): array
{
    $plan = [];
    $add = function (string $kind, int $ref, string $modeKey, string $reason) use (&$plan, $modes): void {
        if ($ref <= 0) {
            return;
        }
        $plan[] = ['kind' => $kind, 'ref_id' => $ref, 'mode' => $modes[$modeKey] ?? 'off', 'reason' => $reason,
            'dedupe' => automation_dedupe_key($kind, $ref)];
    };
    switch ($event) {
        case 'payment_recorded':
            $add('thank_you_payment', (int)($data['payment_id'] ?? 0), 'auto_thank_payment', 'Payment recorded');
            if (!empty($data['paid_in_full'])) {
                $add('thank_you_business', (int)($data['document_id'] ?? 0), 'auto_thank_business', 'Invoice paid in full');
            }
            break;
        case 'quote_accepted':
            $add('quote_accepted', (int)($data['document_id'] ?? 0), 'auto_quote_accepted', 'Quotation accepted');
            break;
        case 'client_created':
            $add('welcome', (int)($data['client_id'] ?? 0), 'auto_welcome', 'New client');
            break;
        case 'trial_started':
        case 'trial_converted':
            $tid = (int)($data['tenant_id'] ?? 0);
            if ($tid > 0) {
                $plan[] = ['kind' => $event, 'ref_id' => $tid, 'mode' => $modes['auto_' . $event] ?? 'off',
                    'reason' => $event === 'trial_started' ? 'Free trial started' : 'Trial converted to a paid plan',
                    'dedupe' => automation_dedupe_key($event, $tid, (string)($data['day'] ?? date('Y-m-d')))];
            }
            break;
    }
    return $plan;
}

/** Fires the automations for an event. Never throws: errors are logged (and kept on the Outbox row for auto sends). */
function automation_event(PDO $pdo, string $event, array $data): void
{
    try {
        if (function_exists('billing_automation_enabled') && !billing_automation_enabled()) {
            return;
        }
        foreach (automation_plan_event($event, $data, automation_modes()) as $p) {
            $reason = $p['reason'] . automation_reason_detail($pdo, $p['kind'], $p['ref_id']);
            automation_dispatch($pdo, $p['kind'], $p['ref_id'], [], $reason, $p['dedupe'], $p['mode']);
        }
    } catch (Throwable $e) {
        error_log('automation_event ' . $event . ': ' . get_class($e) . ': ' . $e->getMessage());
    }
}

/** ": invoice 170134, MWK2,000.00" - a few words for the Outbox reason. Never throws. */
function automation_reason_detail(PDO $pdo, string $kind, int $refId): string
{
    try {
        if ($kind === 'thank_you_payment') {
            $st = $pdo->prepare('SELECT p.amount, p.currency, d.number FROM cp_payments p LEFT JOIN cp_documents d ON d.id = p.document_id WHERE p.id = ?');
            $st->execute([$refId]);
            $r = $st->fetch();
            return $r ? ': ' . billing_money((float)$r['amount'], (string)$r['currency']) . ($r['number'] ? ' on invoice ' . $r['number'] : '') : '';
        }
        if (in_array($kind, ['thank_you_business', 'quote_accepted', 'reminder', 'recurring_invoice'], true)) {
            $st = $pdo->prepare('SELECT number, kind FROM cp_documents WHERE id = ?');
            $st->execute([$refId]);
            $r = $st->fetch();
            return $r ? ': ' . ($r['kind'] === 'quote' ? 'quotation ' : 'invoice ') . $r['number'] : '';
        }
        if (in_array($kind, automation_trial_kinds(), true)) {
            $st = $pdo->prepare('SELECT name FROM cp_tenants WHERE id = ?');
            $st->execute([$refId]);
            $n = $st->fetchColumn();
            return $n ? ': ' . $n : '';
        }
        if ($kind === 'welcome') {
            $st = $pdo->prepare('SELECT name FROM cp_clients WHERE id = ?');
            $st->execute([$refId]);
            $n = $st->fetchColumn();
            return $n ? ': ' . $n : '';
        }
    } catch (Throwable $e) {
        error_log('automation_reason_detail: ' . $e->getMessage());
    }
    return '';
}

/**
 * One automated email per its mode. Returns ['result' => off|duplicate|pending|sent|failed, 'outbox_id', 'error'].
 * Never throws.
 */
function automation_dispatch(PDO $pdo, string $kind, int $refId, array $opts, string $reason, string $dedupe, string $mode, int $adminId = 0): array
{
    $res = ['result' => 'off', 'outbox_id' => 0, 'error' => ''];
    if (!in_array($mode, ['approve', 'auto'], true)) {
        return $res;
    }
    try {
        $id = outbox_add($pdo, $kind, $refId, $opts, $reason, $dedupe);
        if ($id === 0) {
            $res['result'] = 'duplicate';
            return $res;
        }
        $res['outbox_id'] = $id;
        if ($mode === 'approve') {
            $res['result'] = 'pending';
            return $res;
        }
        $r = outbox_send_row($pdo, $id, $adminId, '');
        $res['result'] = $r['ok'] ? 'sent' : 'failed';
        $res['error'] = $r['error'];
    } catch (Throwable $e) {
        error_log('automation_dispatch ' . $kind . ' #' . $refId . ': ' . get_class($e) . ': ' . $e->getMessage());
        $res['result'] = 'failed';
        $res['error'] = 'The automation could not run: ' . $e->getMessage();
        if ($res['outbox_id'] > 0) {
            try {
                // only a row that never reached sending: a row past the claim keeps its own honest state
                $pdo->prepare("UPDATE cp_outbox SET status = 'failed', error = ? WHERE id = ? AND status = 'pending'")
                    ->execute([mb_substr($res['error'], 0, 500, 'UTF-8'), $res['outbox_id']]);
            } catch (Throwable $e2) {
                error_log('automation_dispatch mark failed: ' . $e2->getMessage());
            }
        }
    }
    return $res;
}

/** [client_id, document_id] for an Outbox row (read only). */
function outbox_resolve_refs(PDO $pdo, string $kind, int $refId): array
{
    try {
        if (in_array($kind, ['welcome', 'statement', 'payment_request'], true)) {
            return [$refId, null];
        }
        if (in_array($kind, automation_trial_kinds(), true)) { // ref_id is the business: its client, no document
            $c = $pdo->prepare('SELECT id FROM cp_clients WHERE tenant_id = ?');
            $c->execute([$refId]);
            $cid = $c->fetchColumn();
            return [$cid ? (int)$cid : null, null];
        }
        if (in_array($kind, ['receipt', 'thank_you_payment'], true)) {
            $st = $pdo->prepare('SELECT p.client_id, p.document_id, d.client_id AS doc_client, p.tenant_id FROM cp_payments p
                                 LEFT JOIN cp_documents d ON d.id = p.document_id WHERE p.id = ?');
            $st->execute([$refId]);
            $r = $st->fetch();
            if (!$r) {
                return [null, null];
            }
            $cid = $r['client_id'] ?: $r['doc_client'];
            if (!$cid && $r['tenant_id']) {
                $c = $pdo->prepare('SELECT id FROM cp_clients WHERE tenant_id = ?');
                $c->execute([(int)$r['tenant_id']]);
                $cid = $c->fetchColumn() ?: null;
            }
            return [$cid ? (int)$cid : null, $r['document_id'] ? (int)$r['document_id'] : null];
        }
        $st = $pdo->prepare('SELECT d.client_id, d.tenant_id, c.id AS tenant_client FROM cp_documents d
                             LEFT JOIN cp_clients c ON c.tenant_id = d.tenant_id WHERE d.id = ?');
        $st->execute([$refId]);
        $r = $st->fetch();
        if (!$r) {
            return [null, $refId];
        }
        $cid = $r['client_id'] ?: $r['tenant_client'];
        return [$cid ? (int)$cid : null, $refId];
    } catch (Throwable $e) {
        error_log('outbox_resolve_refs: ' . $e->getMessage());
        return [null, null];
    }
}

/** Adds a pending Outbox row. Returns its id, or 0 when a row with the same dedupe key already exists. */
function outbox_add(PDO $pdo, string $kind, int $refId, array $opts, string $reason, string $dedupe): int
{
    [$clientId, $docId] = outbox_resolve_refs($pdo, $kind, $refId);
    $reason = mb_substr(trim(preg_replace('/\s+/', ' ', $reason)), 0, 190, 'UTF-8');
    $json = $opts ? json_encode($opts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : null;
    try {
        $pdo->prepare(
            "INSERT INTO cp_outbox (kind, ref_id, client_id, document_id, reason, opts_json, status, dedupe_key, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)"
        )->execute([substr($kind, 0, 30), $refId, $clientId, $docId, $reason !== '' ? $reason : billing_mail_kind_label_safe($kind), $json,
            substr($dedupe, 0, 120), date('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            return 0; // already queued or sent: the dedupe key is the claim
        }
        throw $e;
    }
    $id = (int)$pdo->lastInsertId();
    audit_log($pdo, null, 'outbox_added', null, null, ['outbox_id' => $id, 'kind' => $kind, 'ref_id' => $refId, 'client_id' => $clientId,
        'document_id' => $docId, 'dedupe_key' => $dedupe, 'reason' => $reason]);
    return $id;
}

function billing_mail_kind_label_safe(string $kind): string
{
    if (!function_exists('billing_mail_kind_label')) {
        require_once __DIR__ . '/billing_mail.php';
    }
    return billing_mail_kind_label($kind);
}

function outbox_find(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM cp_outbox WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

/** Pure. '' when $action is allowed on a row in $status, else the reason in plain words. */
function outbox_decision_error(string $status, string $action): string
{
    if (!in_array($action, ['send', 'skip'], true)) {
        return 'Unknown action.';
    }
    if ($status === 'sent') {
        return 'This email has already been sent.';
    }
    if ($action === 'send') {
        return in_array($status, ['pending', 'failed', 'skipped'], true) ? '' : 'This email cannot be sent.';
    }
    if ($status === 'skipped') {
        return 'This email has already been skipped.';
    }
    return in_array($status, ['pending', 'failed'], true) ? '' : 'This email cannot be skipped.';
}

/** The statuses a row may be in for $action (the conditional UPDATE that claims it). Pure. */
function outbox_claimable(string $action): array
{
    return $action === 'send' ? ['pending', 'failed', 'skipped'] : ['pending', 'failed'];
}

// While a row is being sent it is held as 'failed' carrying this exact note (no schema change needed). It only becomes
// 'sent' once the email has really gone, so a crash or timeout mid-send can never leave a row that says "Sent" when it
// was not. For OUTBOX_SENDING_MINUTES nobody else can send or skip it; after that it is an interrupted send the owner
// checks before retrying.
const OUTBOX_SENDING_NOTE = 'Sending was started but never confirmed: it may or may not have gone out. Check your Sent mail before you retry.';
const OUTBOX_SENDING_MINUTES = 10;

/** Pure. 'sending' (being sent right now), 'interrupted' (a send that never finished) or '' for an Outbox row. */
function outbox_send_state(array $row, ?int $now = null): string
{
    if ((string)($row['status'] ?? '') !== 'failed' || (string)($row['error'] ?? '') !== OUTBOX_SENDING_NOTE) {
        return '';
    }
    $at = strtotime((string)($row['decided_at'] ?? ''));
    $now = $now ?? time();
    return $at !== false && $now - $at < OUTBOX_SENDING_MINUTES * 60 ? 'sending' : 'interrupted';
}

/** SQL (with 2 placeholders: note, cut-off time) that leaves out rows being sent right now. */
function outbox_not_sending_sql(): string
{
    return "NOT (status = 'failed' AND COALESCE(error, '') = ? AND COALESCE(decided_at, '1970-01-01 00:00:00') > ?)";
}

/** The two values for outbox_not_sending_sql(). */
function outbox_not_sending_args(): array
{
    return [OUTBOX_SENDING_NOTE, date('Y-m-d H:i:s', time() - OUTBOX_SENDING_MINUTES * 60)];
}

/**
 * Sends one Outbox row: claims it with a conditional UPDATE (held as "sending", see OUTBOX_SENDING_NOTE) so two clicks or
 * two runs cannot both send, then sends; only a confirmed send marks it sent, a failure keeps it failed with the reason.
 * $message = optional personal note.
 */
function outbox_send_row(PDO $pdo, int $id, int $adminId, string $message): array
{
    if (!function_exists('billing_send')) {
        require_once __DIR__ . '/billing_mail.php';
    }
    $row = outbox_find($pdo, $id);
    if (!$row) {
        return ['ok' => false, 'error' => 'That Outbox item no longer exists.', 'status' => ''];
    }
    $err = outbox_decision_error((string)$row['status'], 'send');
    if ($err === '' && outbox_send_state($row) === 'sending') {
        $err = 'This email is being sent right now. Refresh the page in a minute.';
    }
    if ($err !== '') {
        return ['ok' => false, 'error' => $err, 'status' => (string)$row['status']];
    }
    $now = date('Y-m-d H:i:s');
    $in = outbox_claimable('send');
    $claim = $pdo->prepare('UPDATE cp_outbox SET status = \'failed\', error = ?, decided_by = ?, decided_at = ? WHERE id = ? AND status IN (?, ?, ?) AND '
        . outbox_not_sending_sql());
    $claim->execute(array_merge([OUTBOX_SENDING_NOTE, $adminId > 0 ? $adminId : null, $now, $id, $in[0], $in[1], $in[2]], outbox_not_sending_args()));
    if ($claim->rowCount() !== 1) {
        return ['ok' => false, 'error' => 'This email is already being handled.', 'status' => (string)$row['status']];
    }
    $opts = json_decode((string)($row['opts_json'] ?? ''), true);
    $opts = is_array($opts) ? $opts : [];
    $message = trim($message);
    if ($message !== '') {
        $opts['message'] = mb_substr($message, 0, 2000, 'UTF-8');
    }
    try {
        $r = billing_send($pdo, (string)$row['kind'], (int)$row['ref_id'], $opts, $adminId);
    } catch (Throwable $e) {
        error_log('outbox_send_row #' . $id . ': ' . get_class($e) . ': ' . $e->getMessage());
        $r = ['ok' => false, 'error' => 'The email could not be sent: ' . $e->getMessage(), 'to' => [], 'test' => false];
    }
    if ($r['ok']) {
        // only our own claim (same note and time): a send that hung past the window cannot overwrite a newer attempt
        $pdo->prepare("UPDATE cp_outbox SET status = 'sent', error = NULL WHERE id = ? AND status = 'failed' AND error = ? AND decided_at = ?")
            ->execute([$id, OUTBOX_SENDING_NOTE, $now]);
    } else {
        $pdo->prepare("UPDATE cp_outbox SET status = 'failed', error = ? WHERE id = ? AND status = 'failed' AND error = ? AND decided_at = ?")
            ->execute([mb_substr((string)$r['error'], 0, 500, 'UTF-8'), $id, OUTBOX_SENDING_NOTE, $now]);
    }
    try {
        audit_log($pdo, $adminId > 0 ? $adminId : null, $r['ok'] ? 'outbox_sent' : 'outbox_send_failed', null,
            ['outbox_id' => $id, 'status' => $row['status']],
            ['outbox_id' => $id, 'kind' => $row['kind'], 'ref_id' => (int)$row['ref_id'], 'status' => $r['ok'] ? 'sent' : 'failed',
                'to' => $r['to'] ?? [], 'test' => !empty($r['test']), 'note' => $message !== '', 'error' => $r['ok'] ? null : $r['error']]);
    } catch (Throwable $e) { // the email went (or not) either way: a missing audit line must not change the answer
        error_log('outbox_send_row audit #' . $id . ': ' . $e->getMessage());
    }
    return ['ok' => (bool)$r['ok'], 'error' => (string)$r['error'], 'status' => $r['ok'] ? 'sent' : 'failed', 'to' => $r['to'] ?? [],
        'test' => !empty($r['test'])];
}

/** The owner's decision on an Outbox row: send (also retry a failed one) or skip. Returns ['ok','error','status']. */
function outbox_decide(PDO $pdo, int $id, string $action, int $adminId, string $message = ''): array
{
    $row = outbox_find($pdo, $id);
    if (!$row) {
        return ['ok' => false, 'error' => 'That Outbox item no longer exists.', 'status' => ''];
    }
    $err = outbox_decision_error((string)$row['status'], $action);
    if ($err !== '') {
        return ['ok' => false, 'error' => $err, 'status' => (string)$row['status']];
    }
    if ($action === 'send') {
        return outbox_send_row($pdo, $id, $adminId, $message);
    }
    if (outbox_send_state($row) === 'sending') {
        return ['ok' => false, 'error' => 'This email is being sent right now. Refresh the page in a minute.', 'status' => (string)$row['status']];
    }
    $note = trim($message);
    $in = outbox_claimable('skip');
    $st = $pdo->prepare("UPDATE cp_outbox SET status = 'skipped', error = ?, decided_by = ?, decided_at = ? WHERE id = ? AND status IN (?, ?) AND "
        . outbox_not_sending_sql());
    $st->execute(array_merge([$note !== '' ? mb_substr('Skipped: ' . $note, 0, 500, 'UTF-8') : null, $adminId > 0 ? $adminId : null, date('Y-m-d H:i:s'), $id,
        $in[0], $in[1]], outbox_not_sending_args()));
    if ($st->rowCount() !== 1) {
        return ['ok' => false, 'error' => 'This email has already been handled.', 'status' => ''];
    }
    audit_log($pdo, $adminId > 0 ? $adminId : null, 'outbox_skipped', null, ['outbox_id' => $id, 'status' => $row['status']],
        ['outbox_id' => $id, 'kind' => $row['kind'], 'ref_id' => (int)$row['ref_id'], 'status' => 'skipped', 'note' => $note]);
    return ['ok' => true, 'error' => '', 'status' => 'skipped'];
}

/** Outbox rows with the client's name, newest first (waiting: oldest first). */
function outbox_list(PDO $pdo, array $statuses, int $limit = 100, bool $oldestFirst = false): array
{
    $statuses = array_values(array_intersect($statuses, ['pending', 'sent', 'failed', 'skipped']));
    if (!$statuses) {
        return [];
    }
    $in = implode(',', array_fill(0, count($statuses), '?'));
    $st = $pdo->prepare(
        'SELECT o.*, c.name AS client_name, d.number AS document_number, d.kind AS document_kind FROM cp_outbox o
         LEFT JOIN cp_clients c ON c.id = o.client_id LEFT JOIN cp_documents d ON d.id = o.document_id
         WHERE o.status IN (' . $in . ') ORDER BY o.id ' . ($oldestFirst ? 'ASC' : 'DESC') . ' LIMIT ' . max(1, min(500, $limit))
    );
    $st->execute($statuses);
    return $st->fetchAll();
}

/** status => count. */
function outbox_counts(PDO $pdo): array
{
    $out = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM cp_outbox GROUP BY status') as $r) {
        $out[(string)$r['status']] = (int)$r['n'];
    }
    return $out;
}

/**
 * Preview of an Outbox row without sending or building PDFs: ['ok','error','subject','to','cc','lines','attachments'].
 * 'lines' = the first lines of the plain-text email (after the title line).
 */
function outbox_preview(PDO $pdo, array $row, int $lines = 6): array
{
    if (!function_exists('billing_mail_build')) {
        require_once __DIR__ . '/billing_mail.php';
    }
    $opts = json_decode((string)($row['opts_json'] ?? ''), true);
    try {
        $b = billing_mail_build($pdo, (string)$row['kind'], (int)$row['ref_id'], is_array($opts) ? $opts : [], false);
    } catch (Throwable $e) {
        error_log('outbox_preview #' . (int)$row['id'] . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The preview could not be built.', 'subject' => '', 'to' => [], 'cc' => [], 'lines' => [], 'attachments' => []];
    }
    return ['ok' => $b['ok'], 'error' => $b['error'], 'subject' => $b['subject'], 'to' => $b['to'], 'cc' => $b['cc'],
        'lines' => outbox_text_lines((string)$b['text'], $lines), 'attachments' => $b['names']];
}

/** Pure. The first $n non-empty lines of a plain-text email, skipping its title line. */
function outbox_text_lines(string $text, int $n): array
{
    $all = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: []), 'strlen'));
    array_shift($all);
    return array_slice($all, 0, max(0, $n));
}

/** The last $limit emails of a kind that went out (sent or test), newest first. */
function automation_recent(PDO $pdo, string $kind, int $limit = 10): array
{
    $st = $pdo->prepare("SELECT l.created_at, l.to_email, l.subject, l.status, l.document_id, c.name AS client_name FROM cp_email_log l
                         LEFT JOIN cp_clients c ON c.id = l.client_id
                         WHERE l.kind = ? AND l.status IN ('sent','test') ORDER BY l.id DESC LIMIT " . max(1, min(50, $limit)));
    $st->execute([$kind]);
    return $st->fetchAll();
}

/** Pending reminders whose invoice is no longer open (paid, void or no balance) are skipped. Returns how many. */
function outbox_tidy_reminders(PDO $pdo): int
{
    $st = $pdo->prepare(
        "UPDATE cp_outbox o JOIN cp_documents d ON d.id = o.ref_id
         SET o.status = 'skipped', o.error = 'Skipped automatically: the invoice is no longer open.', o.decided_at = ?
         WHERE o.kind = 'reminder' AND o.status = 'pending' AND (d.status <> 'sent' OR d.total - d.amount_paid <= ?)"
    );
    $st->execute([date('Y-m-d H:i:s'), defined('BILLING_TOLERANCE') ? BILLING_TOLERANCE : 0.005]);
    $n = $st->rowCount();
    if ($n > 0) {
        audit_log($pdo, null, 'outbox_reminders_tidied', null, null, ['skipped' => $n]);
    }
    return $n;
}

/** When a newer reminder is queued for an invoice, older pending ones for it are skipped (one waiting reminder per invoice). */
function outbox_supersede_reminders(PDO $pdo, int $docId, int $keepId): int
{
    $st = $pdo->prepare(
        "UPDATE cp_outbox SET status = 'skipped', error = 'Skipped automatically: replaced by a newer reminder.', decided_at = ?
         WHERE kind = 'reminder' AND ref_id = ? AND status = 'pending' AND id <> ?"
    );
    $st->execute([date('Y-m-d H:i:s'), $docId, $keepId]);
    return $st->rowCount();
}

function outbox_exists(PDO $pdo, string $dedupe): bool
{
    $st = $pdo->prepare('SELECT 1 FROM cp_outbox WHERE dedupe_key = ?');
    $st->execute([$dedupe]);
    return (bool)$st->fetchColumn();
}

// ---------------------------------------------------------------------------------------------------------------
// The daily job
// ---------------------------------------------------------------------------------------------------------------

function billing_daily_lock_path(): string
{
    return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__)) . '/storage/billing-daily.lock';
}

/** An exclusive, non-blocking lock: the handle, or null when another run holds it (or the file cannot be opened). */
function billing_daily_lock(string $path)
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $h = @fopen($path, 'c');
    if ($h === false) {
        return null;
    }
    if (!flock($h, LOCK_EX | LOCK_NB)) {
        fclose($h);
        return null;
    }
    ftruncate($h, 0);
    fwrite($h, (string)getmypid() . ' ' . date('Y-m-d H:i:s'));
    fflush($h);
    return $h;
}

function billing_daily_unlock($h): void
{
    if (is_resource($h)) {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

/**
 * The daily billing run. Steps, in order: lock -> recurring (due schedules become invoices; each is sent, queued in the
 * Outbox or left as a draft per the schedule's send_mode) -> reminders (per auto_reminders) -> summary.
 * $dryRun: nothing is written or sent (no lock, no invoices, no Outbox rows); the result says what would happen.
 * Idempotent: schedules advance their next_date, and every email has a dedupe key.
 * $deps (tests inject fakes): lock, unlock, modes, recurring_due, recurring_run, due_reminders, dispatch, exists, tidy,
 * supersede, trials_due, steps (default ['recurring','reminders','trials']).
 * Step 'trials' (Part 3): trial_ending / trial_ended emails per auto_trial_ending / auto_trial_ended (nothing when both off).
 * Returns ['ok','dry_run','locked','steps','recurring'=>[items],'reminders'=>[items],'trials'=>[items],'notes','failures','counts','summary'];
 * an item is ['label','result','detail'].
 */
function billing_daily(PDO $pdo, string $today, bool $dryRun = false, array $deps = []): array
{
    $d = $deps + [
        'lock' => function () {
            return billing_daily_lock(billing_daily_lock_path());
        },
        'unlock' => 'billing_daily_unlock',
        'modes' => 'automation_modes',
        'recurring_due' => function (PDO $pdo, string $today) {
            return function_exists('recurring_due') ? recurring_due($pdo, $today) : null;
        },
        'recurring_run' => function (PDO $pdo, int $id, string $today) {
            return recurring_run_one($pdo, $id, 0, $today);
        },
        'due_reminders' => function (PDO $pdo, string $today) {
            return billing_due_reminders($pdo, $today);
        },
        'dispatch' => 'automation_dispatch',
        'exists' => 'outbox_exists',
        'tidy' => 'outbox_tidy_reminders',
        'supersede' => 'outbox_supersede_reminders',
        'trials_due' => function (PDO $pdo, string $today) {
            return function_exists('trials_due') ? trials_due($pdo, $today) : [];
        },
        'steps' => ['recurring', 'reminders', 'trials'],
    ];
    $r = ['ok' => true, 'dry_run' => $dryRun, 'locked' => false, 'today' => $today, 'steps' => [], 'recurring' => [], 'reminders' => [], 'trials' => [],
        'notes' => [], 'failures' => [],
        'counts' => ['invoices' => 0, 'sent' => 0, 'queued' => 0, 'drafts' => 0, 'failed' => 0, 'duplicates' => 0]];

    $lock = null;
    if (!$dryRun) {
        $lock = ($d['lock'])();
        $r['steps'][] = 'lock';
        if (!$lock) {
            $r['ok'] = false;
            $r['locked'] = true;
            $r['notes'][] = 'Another run is already in progress, so this one did nothing.';
            $r['summary'] = billing_daily_summary($r);
            return $r;
        }
    }
    try {
        $modes = ($d['modes'])();

        if (in_array('recurring', (array)$d['steps'], true)) {
            $r['steps'][] = 'recurring';
            try {
                $due = ($d['recurring_due'])($pdo, $today);
            } catch (Throwable $e) {
                error_log('billing_daily recurring_due: ' . $e->getMessage());
                $due = [];
                $r['failures'][] = 'Recurring invoices could not be read: ' . $e->getMessage();
            }
            if ($due === null) {
                $r['notes'][] = 'Recurring invoices are not available yet.';
                $due = [];
            }
            foreach ($due as $s) {
                $sid = (int)($s['id'] ?? 0);
                $title = (string)($s['title'] ?? ('Schedule #' . $sid));
                $mode = (string)($s['send_mode'] ?? 'approve');
                $label = $title . (isset($s['client_name']) ? ' (' . $s['client_name'] . ')' : '');
                if ($dryRun) {
                    $r['recurring'][] = ['label' => $label, 'result' => 'would_create',
                        'detail' => $mode === 'auto' ? 'Invoice would be created and sent' : ($mode === 'draft' ? 'Invoice would be created as a draft'
                            : 'Invoice would be created and waits in the Outbox')];
                    continue;
                }
                try {
                    $run = ($d['recurring_run'])($pdo, $sid, $today);
                } catch (Throwable $e) {
                    $run = ['ok' => false, 'error' => $e->getMessage()];
                }
                if (empty($run['ok'])) {
                    $r['counts']['failed']++;
                    $r['failures'][] = $title . ': ' . (string)($run['error'] ?? 'could not create the invoice');
                    $r['recurring'][] = ['label' => $label, 'result' => 'failed', 'detail' => (string)($run['error'] ?? '')];
                    continue;
                }
                $r['counts']['invoices']++;
                $docId = (int)($run['document_id'] ?? 0);
                $mode = (string)($run['send_mode'] ?? $mode);
                if ($mode === 'draft' || $docId <= 0) {
                    $r['counts']['drafts']++;
                    $r['recurring'][] = ['label' => $label, 'result' => 'draft', 'detail' => 'Invoice created as a draft', 'document_id' => $docId];
                    continue;
                }
                $x = ($d['dispatch'])($pdo, 'recurring_invoice', $docId, ['title' => $title], 'Regular invoice: ' . $title,
                    automation_dedupe_key('recurring_invoice', $docId), $mode === 'auto' ? 'auto' : 'approve');
                billing_daily_count($r, $x, $title);
                $r['recurring'][] = ['label' => $label, 'result' => $x['result'], 'detail' => (string)($x['error'] ?? ''), 'document_id' => $docId];
            }
        }

        if (in_array('reminders', (array)$d['steps'], true)) {
            $r['steps'][] = 'reminders';
            $mode = (string)($modes['auto_reminders'] ?? 'off');
            if ($mode === 'off') {
                $r['notes'][] = 'Automatic reminders are off.';
            } else {
                if (!$dryRun) {
                    try {
                        ($d['tidy'])($pdo);
                    } catch (Throwable $e) {
                        error_log('billing_daily tidy: ' . $e->getMessage());
                    }
                }
                try {
                    $due = ($d['due_reminders'])($pdo, $today);
                } catch (Throwable $e) {
                    error_log('billing_daily due_reminders: ' . $e->getMessage());
                    $due = [];
                    $r['failures'][] = 'Reminders could not be read: ' . $e->getMessage();
                }
                foreach ($due as $row) {
                    $id = (int)($row['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $num = (string)($row['number'] ?? ('#' . $id));
                    $days = isset($row['days']) ? (int)$row['days'] : 0;
                    $words = $days < 0 ? 'due in ' . (-$days) . ' day' . ($days === -1 ? '' : 's') : ($days === 0 ? 'due today' : $days . ' day' . ($days === 1 ? '' : 's') . ' overdue');
                    $label = 'Invoice ' . $num . ' (' . $words . ')';
                    $key = automation_dedupe_key('reminder', $id, $today);
                    if ($dryRun) {
                        $already = ($d['exists'])($pdo, $key);
                        $r['reminders'][] = ['label' => $label, 'result' => $already ? 'duplicate' : ($mode === 'auto' ? 'would_send' : 'would_queue'),
                            'detail' => $already ? 'Already handled today' : ''];
                        continue;
                    }
                    $x = ($d['dispatch'])($pdo, 'reminder', $id, [], 'Reminder: invoice ' . $num . ', ' . $words, $key, $mode);
                    if (($x['result'] ?? '') === 'pending' && !empty($x['outbox_id'])) {
                        ($d['supersede'])($pdo, $id, (int)$x['outbox_id']);
                    }
                    billing_daily_count($r, $x, 'Reminder ' . $num);
                    $r['reminders'][] = ['label' => $label, 'result' => $x['result'], 'detail' => (string)($x['error'] ?? '')];
                }
            }
        }

        if (in_array('trials', (array)$d['steps'], true)) {
            $r['steps'][] = 'trials';
            $tModes = ['trial_ending' => (string)($modes['auto_trial_ending'] ?? 'off'), 'trial_ended' => (string)($modes['auto_trial_ended'] ?? 'off')];
            if ($tModes['trial_ending'] !== 'off' || $tModes['trial_ended'] !== 'off') {
                try {
                    $due = ($d['trials_due'])($pdo, $today);
                } catch (Throwable $e) {
                    error_log('billing_daily trials_due: ' . $e->getMessage());
                    $due = [];
                    $r['failures'][] = 'Free trials could not be read: ' . $e->getMessage();
                }
                foreach ($due as $t) {
                    $kind = (string)($t['kind'] ?? '');
                    $tid = (int)($t['tenant_id'] ?? 0);
                    $mode = $tModes[$kind] ?? 'off';
                    if ($tid <= 0 || $mode === 'off') {
                        continue;
                    }
                    $days = (int)($t['days'] ?? 0);
                    $what = $kind === 'trial_ending' ? 'free trial ends in ' . $days . ' day' . ($days === 1 ? '' : 's') : 'free trial ended';
                    $label = (string)($t['name'] ?? ('Business #' . $tid)) . ' (' . $what . ')';
                    $key = (string)($t['dedupe'] ?? automation_dedupe_key($kind, $tid, $today));
                    if ($dryRun) {
                        $already = ($d['exists'])($pdo, $key);
                        $r['trials'][] = ['label' => $label, 'result' => $already ? 'duplicate' : ($mode === 'auto' ? 'would_send' : 'would_queue'),
                            'detail' => $already ? 'Already handled' : ''];
                        continue;
                    }
                    $x = ($d['dispatch'])($pdo, $kind, $tid, ['today' => $today], ucfirst($what) . ': ' . (string)($t['name'] ?? ''), $key, $mode);
                    billing_daily_count($r, $x, 'Trial ' . (string)($t['name'] ?? $tid));
                    $r['trials'][] = ['label' => $label, 'result' => $x['result'], 'detail' => (string)($x['error'] ?? '')];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('billing_daily: ' . get_class($e) . ': ' . $e->getMessage());
        $r['failures'][] = 'The daily run stopped: ' . $e->getMessage();
    } finally {
        if ($lock) {
            ($d['unlock'])($lock);
        }
    }
    $r['steps'][] = 'summary';
    $r['ok'] = !$r['failures'];
    $r['summary'] = billing_daily_summary($r);
    return $r;
}

function billing_daily_count(array &$r, array $x, string $what): void
{
    switch ($x['result'] ?? '') {
        case 'sent':
            $r['counts']['sent']++;
            break;
        case 'pending':
            $r['counts']['queued']++;
            break;
        case 'duplicate':
            $r['counts']['duplicates']++;
            break;
        case 'failed':
            $r['counts']['failed']++;
            $r['failures'][] = $what . ': ' . (string)($x['error'] ?? 'not sent');
            break;
    }
}

/** Pure. One line: "billing-daily: 1 invoice created, 2 sent, 3 waiting in the Outbox, 0 failed". */
function billing_daily_summary(array $r): string
{
    if (!empty($r['locked'])) {
        return 'billing-daily: another run is in progress, nothing done';
    }
    $c = $r['counts'] + ['invoices' => 0, 'sent' => 0, 'queued' => 0, 'drafts' => 0, 'failed' => 0, 'duplicates' => 0];
    if (!empty($r['dry_run'])) {
        $would = ['would_create' => 0, 'would_send' => 0, 'would_queue' => 0, 'duplicate' => 0];
        foreach (array_merge($r['recurring'] ?? [], $r['reminders'] ?? [], $r['trials'] ?? []) as $it) {
            if (isset($would[$it['result']])) {
                $would[$it['result']]++;
            }
        }
        return 'billing-daily (dry run): ' . $would['would_create'] . ' invoice(s) would be created, ' . $would['would_send'] . ' reminder(s) would be sent, '
            . $would['would_queue'] . ' would wait in the Outbox, ' . $would['duplicate'] . ' already handled';
    }
    $line = 'billing-daily: ' . $c['invoices'] . ' invoice(s) created (' . $c['drafts'] . ' draft), ' . $c['sent'] . ' email(s) sent, '
        . $c['queued'] . ' waiting in the Outbox, ' . $c['duplicates'] . ' already handled, ' . $c['failed'] . ' failed';
    $fails = (array)($r['failures'] ?? []);
    if ($fails) {
        $line .= ': ' . implode('; ', $fails);
    }
    foreach ((array)($r['notes'] ?? []) as $n) {
        $line .= ' [' . $n . ']';
    }
    return $line;
}
