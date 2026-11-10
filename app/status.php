<?php
// Pure status logic. No DB, no clock reads: callers pass "today" (Y-m-d).

function status_list(): array
{
    return ['active', 'grace', 'read_only', 'suspended'];
}

function status_rank(string $s): int
{
    $i = array_search($s, status_list(), true);
    return $i === false ? 3 : $i; // unknown = strictest
}

function status_add_days(string $date, int $days): string
{
    $d = new DateTimeImmutable($date, new DateTimeZone('UTC'));
    return $d->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

/**
 * Month arithmetic (owner 2026-11-10: a month end stays a month end). A date on the last day of its month moves to the
 * last day of the target month (31 Jan + 1 = 28 Feb, 28 Feb + 1 = 31 Mar, 30 Apr + 1 = 31 May), so month-end dates never
 * drift. Any other day is kept, or clamped to the month's last day (30 Jan + 1 = 28 Feb; never spills into March).
 * $anchorDay (1..31, optional): the day to aim for instead of $date's own day (a schedule set up on the 30th keeps
 * coming back to the 30th: 28 Feb + 1 = 30 Mar); 31 means "the last day".
 */
function status_add_months(string $date, int $months, ?int $anchorDay = null): string
{
    $parts = explode('-', substr($date, 0, 10));
    $y = (int)$parts[0];
    $m = (int)$parts[1];
    $day = (int)$parts[2];
    $total = $y * 12 + ($m - 1) + $months;
    $ny = intdiv($total, 12);
    $nm = $total % 12 + 1;
    $last = (int)gmdate('t', gmmktime(0, 0, 0, $nm, 1, $ny));
    if ($anchorDay !== null && $anchorDay >= 1 && $anchorDay <= 31) {
        $want = $anchorDay;
    } else {
        $want = $day >= (int)gmdate('t', gmmktime(0, 0, 0, $m, 1, $y)) ? 31 : $day; // on its month's last day: aim for the last day
    }
    return sprintf('%04d-%02d-%02d', $ny, $nm, min($want, $last));
}

// override wins; else schedule from paid_until.
function status_compute(array $t, string $today): string
{
    $ov = $t['status_override'] ?? null;
    if ($ov !== null && $ov !== '' && in_array($ov, status_list(), true)) {
        return $ov;
    }
    return status_schedule((string)$t['paid_until'], (int)$t['grace_days'], (int)$t['readonly_days'], $today);
}

function status_schedule(string $paidUntil, int $graceDays, int $readonlyDays, string $today): string
{
    $graceEnd = status_add_days($paidUntil, $graceDays);
    $roEnd = status_add_days($graceEnd, $readonlyDays);
    if ($today <= $paidUntil) {
        return 'active';
    }
    if ($today <= $graceEnd) {
        return 'grace';
    }
    if ($today <= $roEnd) {
        return 'read_only';
    }
    return 'suspended';
}

function status_today(): string
{
    return date('Y-m-d');
}
