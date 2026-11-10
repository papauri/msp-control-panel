<?php
// Free trials and subscription plans (owner 2026-11-09, BILLING.md Part 3). Nothing runs at include time.
//
// A trial: trial_started = today, trial_until = today + N days (1-90), paid_until = trial_until, and the after-trial
// step-down from settings trial_grace_days / trial_readonly_days (the licence endpoint then answers grace / read_only /
// suspended from those dates exactly as for an unpaid licence; data is never deleted). A trial is "on" while trial_until
// is set and trial_converted_at is not. Days left = trial_until - today (0 = the last day of the trial).
// Convert = a recurring schedule (Part 2) starting today + its first invoice (draft, or sent by the caller after the
// commit), normal grace / read-only days restored from default_grace_days / default_readonly_days, trial_converted_at set;
// paid_until moves only when that invoice is paid (its extend_months), so an unpaid first invoice keeps the trial's end.
// A plan = a catalog item with billing_interval (1 monthly, 3 quarterly, 12 yearly).

const TRIAL_MAX_DAYS = 90;
const TRIAL_PRESETS = [7, 14, 30];
const PLAN_INTERVALS = [1 => 'Monthly', 3 => 'Quarterly', 12 => 'Yearly'];

// ---------------------------------------------------------------------------------------------------------------
// Pure rules
// ---------------------------------------------------------------------------------------------------------------

/** Pure. True while the business is on a free trial (trial_until set, not converted). */
function trial_is_on(array $t): bool
{
    return !empty($t['trial_until']) && empty($t['trial_converted_at']);
}

/** Pure. Days from $today to the trial's end: 0 = the last day, negative = ended that many days ago. Null when no trial. */
function trial_days_left(array $t, string $today): ?int
{
    if (empty($t['trial_until'])) {
        return null;
    }
    return billing_days_between($today, (string)$t['trial_until']);
}

/** Pure. A trial length in days (string or int) -> int 1..90, or 0 when invalid. */
function trial_clean_days($v): int
{
    $s = is_int($v) ? (string)$v : (is_string($v) ? trim($v) : '');
    if (!preg_match('/^\d{1,3}$/', $s)) {
        return 0;
    }
    $n = (int)$s;
    return $n >= 1 && $n <= TRIAL_MAX_DAYS ? $n : 0;
}

/** Pure. The add-business / start form: preset '7' | '14' | '30' | 'custom' (+ custom days) | '' (no trial). Returns [days, error]. */
function trial_days_from_form(string $preset, string $custom): array
{
    $preset = trim($preset);
    if ($preset === '' || $preset === '0') {
        return [0, ''];
    }
    $n = $preset === 'custom' ? trial_clean_days($custom) : (in_array((int)$preset, TRIAL_PRESETS, true) && ctype_digit($preset) ? (int)$preset : 0);
    return $n > 0 ? [$n, ''] : [0, 'The trial length must be a whole number of days from 1 to ' . TRIAL_MAX_DAYS . '.'];
}

/** Pure. The fields a trial of $days starting $today sets on the business. */
function trial_dates(string $today, int $days, int $graceDays, int $readonlyDays): array
{
    $until = status_add_days($today, $days);
    return ['trial_started' => $today, 'trial_until' => $until, 'paid_until' => $until, 'grace_days' => max(0, $graceDays),
        'readonly_days' => max(0, $readonlyDays), 'trial_converted_at' => null];
}

/** Pure. The dates of the step-down after a trial (like the licence schedule). */
function trial_step_dates(string $trialUntil, int $graceDays, int $readonlyDays): array
{
    $graceEnd = status_add_days($trialUntil, $graceDays);
    $roEnd = status_add_days($graceEnd, $readonlyDays);
    return ['trial_until' => $trialUntil, 'grace_end' => $graceEnd, 'read_only_from' => status_add_days($graceEnd, 1), 'read_only_end' => $roEnd,
        'locked_from' => status_add_days($roEnd, 1)];
}

/** Pure. "Free trial – 12 days left" / "Free trial – 1 day left" / "Free trial – last day" / "Trial ended"; '' when not on a trial. */
function trial_words(array $t, string $today): string
{
    if (!trial_is_on($t)) {
        return '';
    }
    $n = (int)trial_days_left($t, $today);
    if ($n < 0) {
        return 'Trial ended';
    }
    if ($n === 0) {
        return 'Free trial – last day';
    }
    return 'Free trial – ' . $n . ' day' . ($n === 1 ? '' : 's') . ' left';
}

/** Pure. A trial on and ending within $days (0 = today) from $today (an ended trial is not "ending"). */
function trial_ending_within(array $t, string $today, int $days): bool
{
    if (!trial_is_on($t)) {
        return false;
    }
    $n = (int)trial_days_left($t, $today);
    return $n >= 0 && $n <= $days;
}

/** Pure. Whether a trial may be started. $hasPaid = any payment ever recorded for this business. '' or the reason. */
function trial_start_error(array $t, bool $hasPaid): string
{
    if (trial_is_on($t)) {
        return 'This business is already on a free trial: extend it, end it or convert it instead.';
    }
    if ($hasPaid) {
        return 'A free trial is only for a business that has never paid. This one has payments recorded.';
    }
    if (!empty($t['trial_started'])) {
        return 'This business has already had its free trial (one per business). Extend it, or send a quotation.';
    }
    // a business with paid-up time (set by hand, e.g. Rosalyn's until 2099) must never be cut back to a trial's end date
    if (!empty($t['paid_until']) && (string)$t['paid_until'] > status_add_days(status_today(), 31)) {
        return 'This business is paid up until ' . (string)$t['paid_until'] . ', so a free trial would shorten its licence.';
    }
    return '';
}

/**
 * Pure. Whether the owner may put a business on a NEW free trial (owner 2026-11-10: "for any reason"): any business that
 * is not on a trial right now, also one that had a trial, was converted or has paid. '' or the reason.
 */
function trial_restart_error(array $t): string
{
    return trial_is_on($t) ? 'This business is on a free trial now: lengthen, shorten or end it instead.' : '';
}

/** Pure. '' when a trial may be extended / ended / converted, else the reason. */
function trial_change_error(array $t, string $action, string $today): string
{
    if (!empty($t['trial_converted_at'])) {
        return 'This trial was already converted to a paid plan.';
    }
    if (empty($t['trial_until'])) {
        return 'This business is not on a free trial.';
    }
    if ($action === 'end' && (int)trial_days_left($t, $today) < 0) {
        return 'This trial has already ended.';
    }
    return '';
}

/** Pure. The new end of a trial extended by $days: from the later of its current end and today. */
function trial_extend_until(string $trialUntil, string $today, int $days): string
{
    return status_add_days(max($trialUntil, $today), $days);
}

/**
 * Pure. A change to a running (or ended, not converted) trial's last day (owner 2026-11-10: extend AND shorten).
 * $mode: 'add' (N days, from the later of the last day and today), 'remove' (N days off the last day), 'total' (the
 * whole trial is N days from its start), 'date' (this Y-m-d is the last day). The last day can go back at most to
 * yesterday (= "end now": the step-down starts today) and forward at most TRIAL_MAX_AHEAD_DAYS from today.
 * Returns [new last day, ''] or ['', the reason in words].
 */
const TRIAL_MAX_AHEAD_DAYS = 365;
const TRIAL_CHANGE_MODES = ['add' => 'Add days', 'remove' => 'Take days off', 'total' => 'Make the whole trial this many days'];

function trial_change_until(array $t, string $mode, string $value, string $today): array
{
    $err = trial_change_error($t, 'change', $today);
    if ($err !== '') {
        return ['', $err];
    }
    $cur = substr((string)$t['trial_until'], 0, 10);
    $started = substr((string)($t['trial_started'] ?? ''), 0, 10);
    $yesterday = status_add_days($today, -1);
    $value = trim($value);
    if ($mode === 'date') {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return ['', 'Choose the new last day.'];
        }
        $until = $value;
    } else {
        if (!isset(TRIAL_CHANGE_MODES[$mode])) {
            return ['', 'Choose whether to add days, take days off, or set the whole length.'];
        }
        // add / remove: any whole number up to the limit; total: 1..TRIAL_MAX_DAYS like a new trial
        $n = preg_match('/^\d{1,3}$/', $value) ? (int)$value : 0;
        $max = $mode === 'total' ? TRIAL_MAX_DAYS : TRIAL_MAX_AHEAD_DAYS;
        if ($n < 1 || $n > $max) {
            return ['', 'Type a whole number of days from 1 to ' . $max . '.'];
        }
        if ($mode === 'add') {
            $until = trial_extend_until($cur, $today, $n);
        } elseif ($mode === 'remove') {
            if ($cur < $today) {
                return ['', 'This trial has already ended, so there are no days left to take off.'];
            }
            $until = status_add_days($cur, -$n);
            if ($until < $yesterday) {
                $left = billing_days_between($today, $cur) + 1;
                return ['', 'Only ' . $left . ' day' . ($left === 1 ? '' : 's') . ' left (today included), so ' . $n
                    . ' cannot be taken off. To stop the trial today, use End the trial now.'];
            }
        } else {
            if ($started === '') {
                return ['', 'This trial has no start date. Set the last day instead.'];
            }
            $until = status_add_days($started, $n);
        }
    }
    if ($until < $yesterday) {
        return ['', 'That last day (' . billing_date($until) . ') is already in the past. The earliest is yesterday, which ends the trial today; use End the trial now for that.'];
    }
    if ($until > status_add_days($today, TRIAL_MAX_AHEAD_DAYS)) {
        return ['', 'That is more than ' . TRIAL_MAX_AHEAD_DAYS . ' days from today. Check the number.'];
    }
    if ($until === $cur) {
        return ['', 'The trial already ends on ' . billing_date($cur) . '. Nothing was changed.'];
    }
    return [$until, ''];
}

/**
 * Pure. Paid until after a trial's last day moves from $oldUntil to $newUntil: it follows the trial while it was the
 * trial's own date (or earlier), but paid-up time set some other way is never cut back.
 */
function trial_paid_until_after(string $paidUntil, string $oldUntil, string $newUntil): string
{
    $paidUntil = substr($paidUntil, 0, 10);
    if ($paidUntil === '' || $paidUntil <= substr($oldUntil, 0, 10)) {
        return $newUntil;
    }
    return max($paidUntil, $newUntil);
}

/** Pure. "End now": the trial's last day becomes yesterday, so the step-down starts today. */
function trial_end_now_until(string $today): string
{
    return status_add_days($today, -1);
}

/**
 * Pure. Reminder days before a trial ends from the setting ("7,3,1"): unique whole numbers 1..90, largest first.
 */
function trial_reminder_offsets(string $setting): array
{
    $out = [];
    foreach (explode(',', $setting) as $p) {
        $p = trim($p);
        if ($p !== '' && ctype_digit($p) && (int)$p >= 1 && (int)$p <= TRIAL_MAX_DAYS) {
            $out[(int)$p] = (int)$p;
        }
    }
    krsort($out);
    return array_values($out);
}

/**
 * Pure. Which trial emails are due on $today for these businesses. trial_ending on each reminder day before the end
 * (dedupe per business + day); trial_ended from the day after the end, caught up for up to 3 days if the daily job did
 * not run (dedupe per business + the trial's end date, so it goes once per trial end). Converted trials never get either.
 * Returns [['kind','tenant_id','name','days','dedupe']].
 */
function trial_due_emails(array $tenants, string $today, string $reminderSetting): array
{
    $offsets = trial_reminder_offsets($reminderSetting);
    $out = [];
    foreach ($tenants as $t) {
        if (!trial_is_on($t)) {
            continue;
        }
        $id = (int)($t['id'] ?? 0);
        $n = (int)trial_days_left($t, $today);
        if ($n > 0 && in_array($n, $offsets, true)) {
            $out[] = ['kind' => 'trial_ending', 'tenant_id' => $id, 'name' => (string)($t['name'] ?? ''), 'days' => $n,
                'dedupe' => 'trial_ending:' . $id . ':' . $today];
        } elseif ($n <= -1 && $n >= -3) {
            $out[] = ['kind' => 'trial_ended', 'tenant_id' => $id, 'name' => (string)($t['name'] ?? ''), 'days' => $n,
                'dedupe' => 'trial_ended:' . $id . ':' . substr((string)$t['trial_until'], 0, 10)];
        }
    }
    return $out;
}

/** Pure. A percentage setting ("10", "12.5") -> 0..99.99. */
function plan_discount_pct(string $v): float
{
    $v = trim($v);
    if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $v)) {
        return 0.0;
    }
    return round((float)$v, 2);
}

/**
 * Pure. Schedule lines for a plan. $yearly: a monthly plan billed once a year (12 x the price, every 12 months) with an
 * optional discount line ("Annual discount (10%)", a negative price). Returns ['interval' => months, 'lines' => [...]].
 */
function plan_lines(array $plan, bool $yearly, float $discountPct): array
{
    $interval = (int)($plan['billing_interval'] ?? 1);
    if ($interval < 1) {
        $interval = 1;
    }
    $price = round((float)($plan['unit_price'] ?? 0), 2);
    $cost = isset($plan['cost_price']) && $plan['cost_price'] !== null && $plan['cost_price'] !== '' ? round((float)$plan['cost_price'], 2) : null;
    // a schedule made from these lines follows the plan's price (or the business's monthly fee) and the discount setting
    $line = ['qty' => 1.0, 'item' => (string)($plan['item'] ?? 'Plan'), 'description' => (string)($plan['description'] ?? ''), 'unit_price' => $price,
        'cost_price' => $cost, 'catalog_item_id' => !empty($plan['id']) ? (int)$plan['id'] : null,
        'follow' => !empty($plan['id']) ? 'plan' : (string)($plan['follow'] ?? '')];
    if (!$yearly || $interval !== 1) {
        return ['interval' => $interval, 'lines' => [$line]];
    }
    $line['qty'] = 12.0;
    $line['description'] = trim($line['description'] . "\n12 months, billed yearly.");
    $lines = [$line];
    $gross = billing_line_total(12, $price);
    $pct = max(0.0, min(99.99, $discountPct));
    $disc = round($gross * $pct / 100, 2);
    // Always there, even at 0 %: the schedule follows the setting, so a discount set later reaches it (an invoice
    // leaves out a 0 discount line, recurring_price_items). Costs nothing: known when the plan's cost is, so profit counts it.
    $lines[] = ['qty' => 1.0, 'item' => 'Annual discount (' . rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . '%)',
        'description' => 'For paying 12 months at once.', 'unit_price' => $disc > BILLING_TOLERANCE ? -$disc : 0.0,
        'cost_price' => $cost === null ? null : 0.0, 'catalog_item_id' => null, 'follow' => 'annual_discount'];
    return ['interval' => 12, 'lines' => $lines];
}

/**
 * Pure. '' when a plan can be billed, else why not. A plan at 0 (the seeded plans start at 0) would make free invoices
 * that are "paid" the moment they are sent, and a licence plan would then extend the licence for nothing.
 */
function plan_price_problem(array $plan): string
{
    if (round((float)($plan['unit_price'] ?? 0), 2) <= BILLING_TOLERANCE) {
        return 'The plan "' . (string)($plan['item'] ?? '') . '" has no price yet. Set its price on the Catalog page first.';
    }
    return '';
}

/** Pure. The words for a billing interval: "every month", "every 3 months", "every year". */
function plan_interval_words(int $months): string
{
    if ($months === 1) {
        return 'every month';
    }
    if ($months === 12) {
        return 'every year';
    }
    return 'every ' . $months . ' months';
}

/** Pure. A licence plan extends the business's licence (only for a hosted business): extend by the interval. */
function plan_extend_months(array $plan, ?int $tenantId, int $interval): int
{
    return $tenantId !== null && (string)($plan['category'] ?? '') === 'licence' ? $interval : 0;
}

// ---------------------------------------------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------------------------------------------

function trial_setting_int(string $key, int $default): int
{
    $v = trim(billing_setting($key));
    return ctype_digit($v) && (int)$v <= 365 ? (int)$v : $default;
}

// ---------------------------------------------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------------------------------------------

/** Any payment ever recorded for this business (its own, or its client's). */
function trial_has_paid(PDO $pdo, int $tenantId): bool
{
    $st = $pdo->prepare('SELECT 1 FROM cp_payments p WHERE (p.tenant_id = ?
        OR p.client_id IN (SELECT c.id FROM cp_clients c WHERE c.tenant_id = ?)) AND p.voided_at IS NULL AND p.amount > 0 LIMIT 1');
    $st->execute([$tenantId, $tenantId]);
    return (bool)$st->fetchColumn();
}

/** Runs $fn inside a transaction (the caller's, or its own). Events are flushed after an own commit. */
function trial_tx(PDO $pdo, callable $fn)
{
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $r = $fn();
        if ($own) {
            $pdo->commit();
            billing_events_flush($pdo);
        }
        return $r;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
            billing_events_clear();
        }
        throw $e;
    }
}

/** Starts a free trial. Returns '' or the reason it was refused. Audited; fires trial_started (after the commit). */
function trial_start(PDO $pdo, int $tenantId, int $days, int $adminId, string $today): string
{
    if (trial_clean_days($days) === 0) {
        return 'The trial length must be a whole number of days from 1 to ' . TRIAL_MAX_DAYS . '.';
    }
    return (string)trial_tx($pdo, function () use ($pdo, $tenantId, $days, $adminId, $today) {
        $before = tenant_find($pdo, $tenantId, true);
        if (!$before) {
            return 'Business not found.';
        }
        $err = trial_start_error($before, trial_has_paid($pdo, $tenantId));
        if ($err !== '') {
            return $err;
        }
        $f = trial_dates($today, $days, trial_setting_int('trial_grace_days', 0), trial_setting_int('trial_readonly_days', 3));
        $pdo->prepare('UPDATE cp_tenants SET trial_started = ?, trial_until = ?, paid_until = ?, grace_days = ?, readonly_days = ?, trial_converted_at = NULL WHERE id = ?')
            ->execute([$f['trial_started'], $f['trial_until'], $f['paid_until'], $f['grace_days'], $f['readonly_days'], $tenantId]);
        $after = tenant_find($pdo, $tenantId);
        $after['_trial_days'] = $days;
        audit_log($pdo, $adminId ?: null, 'trial_started', $tenantId, $before, $after);
        billing_event('trial_started', ['tenant_id' => $tenantId, 'day' => $f['trial_started']]);
        return '';
    });
}

/**
 * Puts a business back on a free trial of $days from today, whatever its history (owner 2026-11-10). $reason 5..255
 * characters (audited as trial_restarted). The trial step-down days apply and trial_converted_at is cleared, so it can be
 * extended, ended and converted again. Paid-up time is never cut back: paid until becomes the later of its current date
 * and the trial's last day. The business's active schedules that extend its licence are paused, so no licence invoice
 * goes out during the trial (convert again to restart billing). Fires trial_started after the commit.
 * Returns ['error' => '' | words, 'until' => last day, 'paid_until' => after, 'paused' => schedules paused].
 */
function trial_restart(PDO $pdo, int $tenantId, int $days, string $reason, int $adminId, string $today): array
{
    $reason = trim($reason);
    if (trial_clean_days($days) === 0) {
        return ['error' => 'The trial length must be a whole number of days from 1 to ' . TRIAL_MAX_DAYS . '.', 'until' => '', 'paid_until' => '', 'paused' => 0];
    }
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
        return ['error' => 'Say why it goes back on a free trial (5 to 255 characters).', 'until' => '', 'paid_until' => '', 'paused' => 0];
    }
    return (array)trial_tx($pdo, function () use ($pdo, $tenantId, $days, $reason, $adminId, $today) {
        $before = tenant_find($pdo, $tenantId, true);
        if (!$before) {
            return ['error' => 'Business not found.', 'until' => '', 'paid_until' => '', 'paused' => 0];
        }
        $err = trial_restart_error($before);
        if ($err !== '') {
            return ['error' => $err, 'until' => '', 'paid_until' => '', 'paused' => 0];
        }
        $f = trial_dates($today, $days, trial_setting_int('trial_grace_days', 0), trial_setting_int('trial_readonly_days', 3));
        $paid = max(substr((string)$before['paid_until'], 0, 10), $f['trial_until']);
        $pdo->prepare('UPDATE cp_tenants SET trial_started = ?, trial_until = ?, paid_until = ?, grace_days = ?, readonly_days = ?, trial_converted_at = NULL WHERE id = ?')
            ->execute([$f['trial_started'], $f['trial_until'], $paid, $f['grace_days'], $f['readonly_days'], $tenantId]);
        $st = $pdo->prepare('SELECT * FROM cp_recurring WHERE tenant_id = ? AND is_active = 1 AND extend_months > 0 FOR UPDATE');
        $st->execute([$tenantId]);
        $paused = 0;
        foreach ($st->fetchAll() as $r) {
            $pdo->prepare('UPDATE cp_recurring SET is_active = 0 WHERE id = ?')->execute([(int)$r['id']]);
            $rAfter = recurring_find($pdo, (int)$r['id']);
            $rAfter['_why'] = 'paused: the business went back on a free trial';
            audit_log($pdo, $adminId ?: null, 'recurring_saved', $tenantId, $r, $rAfter);
            $paused++;
        }
        $after = tenant_find($pdo, $tenantId);
        $after['_trial_restart'] = ['days' => $days, 'reason' => mb_substr($reason, 0, 255), 'schedules_paused' => $paused];
        audit_log($pdo, $adminId ?: null, 'trial_restarted', $tenantId, $before, $after);
        billing_event('trial_started', ['tenant_id' => $tenantId, 'day' => $f['trial_started']]);
        return ['error' => '', 'until' => $f['trial_until'], 'paid_until' => $paid, 'paused' => $paused];
    });
}

/** Extends a trial by $days (from the later of its end and today). '' or the reason. Audited. */
function trial_extend(PDO $pdo, int $tenantId, int $days, int $adminId, string $today): string
{
    $r = trial_change($pdo, $tenantId, 'add', (string)$days, $adminId, $today);
    return $r['error'];
}

/**
 * Moves a trial's last day (trial_change_until: add / remove / total / date), with the business locked. Paid until
 * follows (trial_paid_until_after). Returns ['error' => '' | words, 'until' => the new last day]. Audited as
 * trial_extended or trial_shortened. $note (optional) is kept in the audit, e.g. "Free month (1), ref ...".
 */
function trial_change(PDO $pdo, int $tenantId, string $mode, string $value, int $adminId, string $today, string $note = ''): array
{
    return (array)trial_tx($pdo, function () use ($pdo, $tenantId, $mode, $value, $adminId, $today, $note) {
        $before = tenant_find($pdo, $tenantId, true);
        if (!$before) {
            return ['error' => 'Business not found.', 'until' => ''];
        }
        [$until, $err] = trial_change_until($before, $mode, $value, $today);
        if ($err !== '') {
            return ['error' => $err, 'until' => ''];
        }
        $old = substr((string)$before['trial_until'], 0, 10);
        $paid = trial_paid_until_after((string)($before['paid_until'] ?? ''), $old, $until);
        $pdo->prepare('UPDATE cp_tenants SET trial_until = ?, paid_until = ? WHERE id = ?')->execute([$until, $paid, $tenantId]);
        $after = tenant_find($pdo, $tenantId);
        $after['_trial_change'] = ['mode' => $mode, 'value' => $value, 'from' => $old, 'to' => $until] + ($note !== '' ? ['note' => mb_substr($note, 0, 250)] : []);
        audit_log($pdo, $adminId ?: null, $until > $old ? 'trial_extended' : 'trial_shortened', $tenantId, $before, $after);
        return ['error' => '', 'until' => $until];
    });
}

/** Ends a trial now: its last day becomes yesterday, so the step-down starts today. '' or the reason. Audited. */
function trial_end_now(PDO $pdo, int $tenantId, int $adminId, string $today): string
{
    return (string)trial_tx($pdo, function () use ($pdo, $tenantId, $adminId, $today) {
        $before = tenant_find($pdo, $tenantId, true);
        if (!$before) {
            return 'Business not found.';
        }
        $err = trial_change_error($before, 'end', $today);
        if ($err !== '') {
            return $err;
        }
        $until = trial_end_now_until($today);
        $paid = trial_paid_until_after((string)($before['paid_until'] ?? ''), (string)$before['trial_until'], $until);
        $pdo->prepare('UPDATE cp_tenants SET trial_until = ?, paid_until = ? WHERE id = ?')->execute([$until, $paid, $tenantId]);
        audit_log($pdo, $adminId ?: null, 'trial_ended_now', $tenantId, $before, tenant_find($pdo, $tenantId));
        return '';
    });
}

/**
 * Converts a trial to paid. $o: plan_id (a plan) or 0 = the business's monthly fee; yearly (bool, a monthly plan billed
 * yearly with the annual discount); send_mode for the later invoices (auto|approve|draft). In one transaction with the
 * business locked: a recurring schedule starting today, its first invoice (a draft), the normal grace / read-only days,
 * trial_converted_at. Returns ['ok','error','schedule_id','document_id']. Sending the first invoice is the caller's
 * choice, after this returns. Audited; fires trial_converted.
 */
function trial_convert(PDO $pdo, int $tenantId, array $o, int $adminId, string $today): array
{
    $res = ['ok' => false, 'error' => '', 'schedule_id' => 0, 'document_id' => 0];
    try {
        return trial_tx($pdo, function () use ($pdo, $tenantId, $o, $adminId, $today, $res) {
            $before = tenant_find($pdo, $tenantId, true);
            if (!$before) {
                throw new InvalidArgumentException('Business not found.');
            }
            $err = trial_change_error($before, 'convert', $today);
            if ($err !== '') {
                throw new InvalidArgumentException($err);
            }
            $client = client_for_tenant($pdo, $tenantId);
            if (!$client) {
                throw new InvalidArgumentException('The business has no client record.');
            }
            $planId = (int)($o['plan_id'] ?? 0);
            if ($planId > 0) {
                $plan = plan_find($pdo, $planId);
                if (!$plan || (int)$plan['is_active'] !== 1) {
                    throw new InvalidArgumentException('Choose one of the active plans.');
                }
                if (plan_price_problem($plan) !== '') {
                    throw new InvalidArgumentException(plan_price_problem($plan));
                }
                $pl = plan_lines($plan, !empty($o['yearly']), plan_discount_pct(billing_setting('annual_discount_pct')));
                $title = (string)$plan['item'];
                $currency = (string)$plan['currency'];
            } else {
                if ((float)$before['monthly_fee'] <= BILLING_TOLERANCE) {
                    throw new InvalidArgumentException('The business\'s monthly fee is 0: set it under Details, or choose a plan.');
                }
                // The licence line as "Add licence subscription" makes it (plan, type, modules); yearly drops its "1 month" line.
                $sub = billing_subscription_lines($before, 1)[0];
                $desc = (string)$sub['description'];
                if (!empty($o['yearly']) && strrpos($desc, "\n") !== false) {
                    $desc = substr($desc, 0, (int)strrpos($desc, "\n"));
                }
                $plan = ['id' => 0, 'item' => $sub['item'], 'description' => $desc, 'unit_price' => (float)$before['monthly_fee'],
                    'billing_interval' => 1, 'category' => 'licence', 'follow' => 'monthly_fee'];
                $pl = plan_lines($plan, !empty($o['yearly']), plan_discount_pct(billing_setting('annual_discount_pct')));
                $title = (string)$sub['item'];
                $currency = (string)$before['currency'];
            }
            // converting a trial: whichever plan is chosen pays for the system the business trialled, so each paid
            // invoice extends its licence by the plan's interval (whatever the plan's category)
            $extend = $tenantId !== null ? (int)$pl['interval'] : 0;
            $mode = (string)($o['send_mode'] ?? 'approve');
            [$verr, $r] = recurring_validate(['client_id' => (string)$client['id'], 'title' => $title, 'interval_months' => (string)$pl['interval'],
                'next_date' => $today, 'currency' => $currency, 'due_days' => billing_setting('invoice_due_days'), 'extend_months' => (string)$extend,
                'send_mode' => isset(recurring_send_modes()[$mode]) ? $mode : 'approve', 'notes' => ''], array_map(static function ($l) {
                    return ['qty' => (string)$l['qty'], 'item' => $l['item'], 'description' => $l['description'], 'unit_price' => number_format((float)$l['unit_price'], 2, '.', ''),
                        'cost_price' => $l['cost_price'] === null ? '' : number_format((float)$l['cost_price'], 2, '.', ''), 'catalog_item_id' => (string)($l['catalog_item_id'] ?? ''),
                        'follow' => (string)($l['follow'] ?? '')];
                }, $pl['lines']));
            if ($verr) {
                throw new InvalidArgumentException(implode(' ', $verr));
            }
            $sid = recurring_save($pdo, $r, $adminId);
            $run = recurring_run_one($pdo, $sid, $adminId, $today);
            if (!$run['ok']) {
                throw new InvalidArgumentException('The first invoice could not be made: ' . $run['error']);
            }
            $pdo->prepare('UPDATE cp_tenants SET grace_days = ?, readonly_days = ?, trial_converted_at = ? WHERE id = ?')
                ->execute([trial_setting_int('default_grace_days', 14), trial_setting_int('default_readonly_days', 14), date('Y-m-d H:i:s'), $tenantId]);
            $after = tenant_find($pdo, $tenantId);
            $after['_schedule_id'] = $sid;
            $after['_document_id'] = (int)$run['document_id'];
            audit_log($pdo, $adminId ?: null, 'trial_converted', $tenantId, $before, $after);
            billing_event('trial_converted', ['tenant_id' => $tenantId, 'day' => $today]);
            return ['ok' => true, 'error' => '', 'schedule_id' => $sid, 'document_id' => (int)$run['document_id']];
        });
    } catch (InvalidArgumentException $e) {
        $res['error'] = $e->getMessage();
        return $res;
    }
}

/** Businesses on a trial (not converted), for the daily job, the board and reports. */
function trial_tenants(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM cp_tenants WHERE trial_until IS NOT NULL AND trial_converted_at IS NULL ORDER BY trial_until, id')->fetchAll();
}

/** The trial emails due today (trial_due_emails over the businesses on a trial). */
function trials_due(PDO $pdo, string $today): array
{
    return trial_due_emails(trial_tenants($pdo), $today, billing_setting('trial_reminder_days'));
}

/** Trials ending within $days of $today (the billing board and the client page). */
function trials_ending_soon(PDO $pdo, string $today, int $days = 7): array
{
    return array_values(array_filter(trial_tenants($pdo), static fn($t) => trial_ending_within($t, $today, $days)));
}

// ---------------------------------------------------------------------------------------------------------------
// Plans
// ---------------------------------------------------------------------------------------------------------------

function plan_find(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM cp_catalog_items WHERE id = ? AND billing_interval IS NOT NULL');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

function plan_list(PDO $pdo, bool $activeOnly = true): array
{
    return $pdo->query('SELECT * FROM cp_catalog_items WHERE billing_interval IS NOT NULL' . ($activeOnly ? ' AND is_active = 1' : '')
        . ' ORDER BY billing_interval, sort, item')->fetchAll();
}

/** Pure. "PMS licence – Monthly: MWK0.00 every month". */
function plan_label(array $p): string
{
    if (plan_price_problem($p) !== '') {
        return (string)$p['item'] . ': no price yet (set it on the Catalog page)';
    }
    return (string)$p['item'] . ': ' . billing_money((float)$p['unit_price'], (string)$p['currency']) . ' ' . plan_interval_words((int)$p['billing_interval']);
}

/**
 * First use: when no saved item is a plan, adds the usual plans at price 0 for the owner to set. A lock on the cp_settings
 * row 'plans_seeded' makes two first page loads seed only once; audited.
 */
function billing_seed_plans(PDO $pdo, int $adminId = 0): void
{
    if ((billing_settings_cache()['plans_seeded'] ?? '') === '1') {
        return;
    }
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare('INSERT IGNORE INTO cp_settings (setting_key, setting_value) VALUES (\'plans_seeded\', \'0\')')->execute();
        $flag = (string)$pdo->query('SELECT setting_value FROM cp_settings WHERE setting_key = \'plans_seeded\' FOR UPDATE')->fetchColumn();
        if ($flag !== '1') {
            $rows = [];
            if ((int)$pdo->query('SELECT COUNT(*) FROM cp_catalog_items WHERE billing_interval IS NOT NULL')->fetchColumn() === 0) {
                $rows = [
                    ['licence', 'PMS licence – Monthly', 'Hotel management system licence.', 'month', 1, 10],
                    ['service', 'IT Support – Monthly', 'IT support.', 'month', 1, 11],
                    ['hosting', 'Website hosting – Monthly', 'Website hosting.', 'month', 1, 12],
                    ['service', 'Website maintenance – Monthly', 'Website maintenance.', 'month', 1, 13],
                    ['licence', 'PMS licence – Yearly', 'Hotel management system licence, 12 months.', 'year', 12, 14],
                ];
                $cur = billing_setting('default_currency');
                $st = $pdo->prepare('INSERT INTO cp_catalog_items (category, item, description, unit_price, unit, billing_interval, currency, is_active, sort) VALUES (?, ?, ?, \'0.00\', ?, ?, ?, 1, ?)');
                foreach ($rows as $r) {
                    $st->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $cur, $r[5]]);
                }
            }
            $pdo->prepare('UPDATE cp_settings SET setting_value = \'1\' WHERE setting_key = \'plans_seeded\'')->execute();
            audit_log($pdo, $adminId ?: null, 'plans_seeded', null, null, ['plans_added' => count($rows)]);
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

/**
 * "Subscribe" on a client page: a recurring schedule from a plan (+ optional extra lines). $o: plan_id, yearly, next_date,
 * send_mode, extra (lines). Returns ['ok','error','schedule_id']. Audited (recurring_saved).
 */
function plan_subscribe(PDO $pdo, int $clientId, array $o, int $adminId): array
{
    $res = ['ok' => false, 'error' => '', 'schedule_id' => 0];
    $client = client_find($pdo, $clientId);
    if (!$client) {
        $res['error'] = 'That client no longer exists.';
        return $res;
    }
    $plan = plan_find($pdo, (int)($o['plan_id'] ?? 0));
    if (!$plan || (int)$plan['is_active'] !== 1) {
        $res['error'] = 'Choose one of the active plans.';
        return $res;
    }
    if (($pp = plan_price_problem($plan)) !== '') {
        $res['error'] = $pp;
        return $res;
    }
    $pl = plan_lines($plan, !empty($o['yearly']), plan_discount_pct(billing_setting('annual_discount_pct')));
    $tenantId = $client['tenant_id'] !== null ? (int)$client['tenant_id'] : null;
    $lines = array_map(static function ($l) {
        return ['qty' => (string)$l['qty'], 'item' => $l['item'], 'description' => $l['description'], 'unit_price' => number_format((float)$l['unit_price'], 2, '.', ''),
            'cost_price' => $l['cost_price'] === null ? '' : number_format((float)$l['cost_price'], 2, '.', ''), 'catalog_item_id' => (string)($l['catalog_item_id'] ?? ''),
                        'follow' => (string)($l['follow'] ?? '')];
    }, $pl['lines']);
    foreach ((array)($o['extra'] ?? []) as $x) {
        if (is_array($x)) {
            $lines[] = $x;
        }
    }
    [$err, $r] = recurring_validate(['client_id' => (string)$clientId, 'title' => (string)$plan['item'], 'interval_months' => (string)$pl['interval'],
        'next_date' => (string)($o['next_date'] ?? ''), 'currency' => (string)$plan['currency'], 'due_days' => billing_setting('invoice_due_days'),
        'extend_months' => (string)plan_extend_months($plan, $tenantId, (int)$pl['interval']), 'send_mode' => (string)($o['send_mode'] ?? 'approve'),
        'notes' => ''], $lines);
    if ($err) {
        $res['error'] = implode(' ', $err);
        return $res;
    }
    try {
        $res['schedule_id'] = recurring_save($pdo, $r, $adminId);
        $res['ok'] = true;
    } catch (InvalidArgumentException $e) {
        $res['error'] = $e->getMessage();
    }
    return $res;
}

// ---------------------------------------------------------------------------------------------------------------
// Email data (billing_mail_build) and reports
// ---------------------------------------------------------------------------------------------------------------

/**
 * What the trial emails need about a business (read only). $kind trial_started | trial_ending | trial_ended | trial_converted.
 * Returns ['tenant','type','modules','trial' => [started, until, days_left, length, step dates], 'offer' => [[label, amount]],
 * 'plan' => [title, every, amount, first_invoice]].
 */
function trial_mail_data(PDO $pdo, array $tenant, string $kind, string $today): array
{
    $mods = [];
    $labels = function_exists('catalog_modules') ? catalog_modules() : [];
    foreach (function_exists('catalog_tenant_modules') ? catalog_tenant_modules($tenant) : [] as $k) {
        $mods[] = $labels[$k] ?? $k;
    }
    $until = (string)($tenant['trial_until'] ?? '');
    $started = (string)($tenant['trial_started'] ?? '');
    $trial = ['started' => $started, 'until' => $until, 'days_left' => $until !== '' ? billing_days_between($today, $until) : null,
        'length' => $started !== '' && $until !== '' ? billing_days_between($started, $until) : null];
    if ($until !== '') {
        $trial += trial_step_dates($until, (int)$tenant['grace_days'], (int)$tenant['readonly_days']);
    }
    $offer = [];
    $cur = (string)$tenant['currency'];
    $fee = round((float)$tenant['monthly_fee'], 2);
    if ($fee > BILLING_TOLERANCE) {
        $offer[] = ['Monthly', billing_money($fee, $cur) . ' a month'];
        $pct = plan_discount_pct(billing_setting('annual_discount_pct'));
        $yl = plan_lines(['unit_price' => $fee, 'billing_interval' => 1], true, $pct);
        $year = billing_calc_totals($yl['lines'], 0, 0)['total'];
        $offer[] = ['Yearly', billing_money($year, $cur) . ' a year' . ($pct > 0 ? ' (' . rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . '% off)' : '')];
    }
    $plan = null;
    if ($kind === 'trial_converted') {
        $st = $pdo->prepare('SELECT * FROM cp_recurring WHERE tenant_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([(int)$tenant['id']]);
        $r = $st->fetch();
        if ($r) {
            $tot = billing_calc_totals(recurring_priced($pdo, $r)['items'], 0, 0)['total'];
            $num = '';
            if (!empty($r['last_document_id'])) {
                $d = $pdo->prepare('SELECT number FROM cp_documents WHERE id = ?');
                $d->execute([(int)$r['last_document_id']]);
                $num = (string)($d->fetchColumn() ?: '');
            }
            $plan = ['title' => (string)$r['title'], 'every' => plan_interval_words((int)$r['interval_months']),
                'amount' => billing_money($tot, (string)$r['currency']), 'first_invoice' => $num];
        }
    }
    return ['tenant' => $tenant, 'type' => function_exists('catalog_type_label') ? catalog_type_label((string)($tenant['business_type'] ?? 'custom')) : '',
        'modules' => $mods, 'trial' => $trial, 'offer' => $offer, 'plan' => $plan];
}

/**
 * Pure. Trial figures for a period: started (trial_started in the period), converted (trial_converted_at in it), lost (the
 * trial ended in the period, before today, and was not converted), rate = converted / (converted + lost) in %, null when 0.
 */
function reports_trials(array $tenants, string $from, string $to, string $today): array
{
    $out = ['started' => 0, 'converted' => 0, 'lost' => 0, 'rate' => null, 'active' => 0];
    foreach ($tenants as $t) {
        $s = substr((string)($t['trial_started'] ?? ''), 0, 10);
        $u = substr((string)($t['trial_until'] ?? ''), 0, 10);
        $c = substr((string)($t['trial_converted_at'] ?? ''), 0, 10);
        if ($s !== '' && $s >= $from && $s <= $to) {
            $out['started']++;
        }
        if ($c !== '' && $c >= $from && $c <= $to) {
            $out['converted']++;
        }
        if ($c === '' && $u !== '' && $u >= $from && $u <= $to && $u < $today) {
            $out['lost']++;
        }
        if ($c === '' && $u !== '' && $u >= $today) {
            $out['active']++;
        }
    }
    $n = $out['converted'] + $out['lost'];
    $out['rate'] = $n > 0 ? round($out['converted'] / $n * 100, 1) : null;
    return $out;
}

/** Businesses that ever had a trial (read only), for reports. */
function reports_trial_tenants(PDO $pdo): array
{
    return $pdo->query('SELECT id, name, trial_started, trial_until, trial_converted_at FROM cp_tenants WHERE trial_started IS NOT NULL OR trial_until IS NOT NULL')->fetchAll();
}
