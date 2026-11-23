<?php
// Scheduled maintenance windows (owner 2026-11-23): "put our HMS systems offline for maintenance with a nice little banner
// which will hold the nice default generic message about the maintenance and the change window ... send alert to our HMS of
// the scheduled maintenance ahead of time and during that time put the admin or front end offline or both ... and after
// patch window back online."
//
// How it works: a window is saved here (cp_maintenance, migration 014). The next SIGNED licence answer for that business
// carries it (public/api/licence.php, field "maintenance"). The business's own system shows a notice to its staff from
// announce_from, takes the chosen part offline from starts_at and comes back by itself at ends_at, on its own clock, even if
// it never reaches the panel again. End now / Cancel send "maintenance": null, which brings it back at its next check-in
// (the page nudges it straight away). The business echoes the window it holds (maintenance_seen) so the page can show it
// arrived.
//
// Every time is unix seconds. The pure functions take $now (and a time zone where they read a date) so the tests drive them.

const MAINT_MAX_WINDOW = 259200;         // 72 hours: the longest a system may be offline in one window
const MAINT_MAX_ANNOUNCE = 2592000;      // 30 days: the earliest the notice may show before the start
const MAINT_MESSAGE_MAX = 1000;          // characters
const MAINT_START_SLACK = 600;           // a start up to 10 minutes in the past is taken as "now"
const MAINT_SETTING = 'maintenance_default_message';

/** The generic message built into the panel: used when no message of its own is saved on the Maintenance page. */
function maint_builtin_message(): string
{
    return 'We are carrying out scheduled maintenance to keep the system safe and up to date. '
        . 'It is offline for the time shown and comes back on by itself when the work is done. Thank you for your patience.';
}

/** @return array<string,string> scope => plain words */
function maint_scopes(): array
{
    return [
        'admin' => 'Staff system offline (admin, front desk, POS and kitchen screens)',
        'site' => 'Guest website offline',
        'both' => 'Both offline (staff system and guest website)',
    ];
}

/** @return array<string,array{0:string,1:int}> lead key => [words, seconds before the start] (-1 = as soon as it is saved) */
function maint_leads(): array
{
    return [
        'now' => ['As soon as it is saved', -1],
        '1h' => ['1 hour before', 3600],
        '6h' => ['6 hours before', 21600],
        '1d' => ['1 day before', 86400],
        '3d' => ['3 days before', 259200],
        '7d' => ['7 days before', 604800],
        '14d' => ['14 days before', 1209600],
        '30d' => ['30 days before', 2592000],
    ];
}

/** When the staff notice starts, from the chosen lead. Pure. Never after the start. */
function maint_announce_from(int $startsAt, string $lead, int $now): int
{
    $l = maint_leads()[$lead] ?? maint_leads()['1d'];
    $at = $l[1] < 0 ? $now : $startsAt - $l[1];
    return min($at, $startsAt);
}

/** "Y-m-d\TH:i" (an <input type=datetime-local>) in $tz to unix seconds, or null. Pure. */
function maint_parse_local(string $v, string $tz): ?int
{
    $v = trim($v);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $v)) {
        return null;
    }
    try {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $v, new DateTimeZone($tz));
    } catch (Throwable $e) {
        return null;
    }
    $errs = DateTimeImmutable::getLastErrors();
    if ($d === false || (is_array($errs) && ($errs['warning_count'] > 0 || $errs['error_count'] > 0)) || $d->format('Y-m-d\TH:i') !== $v) {
        return null;
    }
    return $d->getTimestamp();
}

/** Plain text, \n line ends, no control characters, trimmed. Pure. */
function maint_clean_message(string $m): string
{
    $m = str_replace(["\r\n", "\r"], "\n", $m);
    $m = (string)preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $m);
    return trim($m);
}

/**
 * Checks one window. Pure. $in: scope, announce_from, starts_at, ends_at (ints), message (string; '' = the generic message).
 * @return array{0:string[],1:array} [errors, clean row fields]
 */
function maint_validate(array $in, int $now): array
{
    $err = [];
    $scope = is_string($in['scope'] ?? null) ? $in['scope'] : '';
    if (!isset(maint_scopes()[$scope])) {
        $err[] = 'Choose what goes offline: the staff system, the guest website, or both.';
    }
    $int = static fn($v): int => is_int($v) ? $v : 0;
    $starts = $int($in['starts_at'] ?? null);
    $ends = $int($in['ends_at'] ?? null);
    $announce = $int($in['announce_from'] ?? null);
    if ($starts <= 0 || $ends <= 0) {
        $err[] = 'Enter a valid start and end.';
    } else {
        if ($ends <= $starts) {
            $err[] = 'The end must be after the start.';
        } elseif ($ends - $starts > MAINT_MAX_WINDOW) {
            $err[] = 'A window can be at most 72 hours long.';
        }
        if ($starts < $now - MAINT_START_SLACK) {
            $err[] = 'The start is in the past.';
        }
        if ($announce <= 0 || $announce > $starts) {
            $err[] = 'The notice must start before the window starts.';
        } elseif ($announce < $starts - MAINT_MAX_ANNOUNCE) {
            $err[] = 'The notice can start at most 30 days before the window.';
        }
    }
    $raw = is_string($in['message'] ?? null) ? $in['message'] : '';
    $msg = maint_clean_message($raw);
    if (mb_strlen($msg) > MAINT_MESSAGE_MAX) {
        $err[] = 'The message can be at most ' . MAINT_MESSAGE_MAX . ' characters.';
    }
    if ($msg !== strip_tags($msg)) {
        $err[] = 'Write the message as plain text (no HTML).';
    }
    return [$err, ['scope' => $scope, 'announce_from' => $announce, 'starts_at' => $starts, 'ends_at' => $ends,
        'message' => $msg === '' ? null : $msg]];
}

/** Does [$starts, $ends) overlap a planned window in $rows (one business)? Pure. $exceptId skips one row. */
function maint_overlaps(array $rows, int $starts, int $ends, int $exceptId = 0): bool
{
    foreach ($rows as $r) {
        if ((string)($r['state'] ?? '') !== 'planned' || (int)($r['id'] ?? 0) === $exceptId) {
            continue;
        }
        if ((int)$r['starts_at'] < $ends && (int)$r['ends_at'] > $starts) {
            return true;
        }
    }
    return false;
}

/** upcoming | announced | offline | ended | cancelled. Pure. */
function maint_state(array $r, int $now): string
{
    $state = (string)($r['state'] ?? '');
    if ($state === 'cancelled') {
        return 'cancelled';
    }
    if ($state !== 'planned' || $now >= (int)$r['ends_at']) {
        return 'ended';
    }
    if ($now >= (int)$r['starts_at']) {
        return 'offline';
    }
    return $now >= (int)$r['announce_from'] ? 'announced' : 'upcoming';
}

/** @return array<string,string> state => the words on the page */
function maint_state_words(): array
{
    return ['upcoming' => 'Upcoming', 'announced' => 'Announced', 'offline' => 'Offline now', 'ended' => 'Ended', 'cancelled' => 'Cancelled'];
}

/**
 * The one window a business is told about: the planned window with ends_at > now that starts first (earliest id on a tie).
 * A later window is sent once the earlier one is over. Pure. Null when there is none.
 */
function maint_pick(array $rows, int $now): ?array
{
    $best = null;
    foreach ($rows as $r) {
        if ((string)($r['state'] ?? '') !== 'planned' || (int)$r['ends_at'] <= $now) {
            continue;
        }
        if ($best === null || (int)$r['starts_at'] < (int)$best['starts_at']
            || ((int)$r['starts_at'] === (int)$best['starts_at'] && (int)$r['id'] < (int)$best['id'])) {
            $best = $r;
        }
    }
    return $best;
}

/** The block for the signed answer. Pure. A window with no message of its own carries the generic one. */
function maint_block(array $r, string $defaultMessage): array
{
    $msg = $r['message'] ?? null;
    return [
        'id' => (int)$r['id'],
        'scope' => (string)$r['scope'],
        'announce_from' => (int)$r['announce_from'],
        'starts_at' => (int)$r['starts_at'],
        'ends_at' => (int)$r['ends_at'],
        'message' => (is_string($msg) && trim($msg) !== '') ? $msg : $defaultMessage,
    ];
}

/** What End now may do: '' when allowed, else why not. Pure. */
function maint_can_end(array $r, int $now): string
{
    $s = maint_state($r, $now);
    return $s === 'offline' ? '' : ($s === 'upcoming' || $s === 'announced' ? 'This window has not started yet: use Cancel.' : 'This window is already over.');
}

/** What Cancel may do: '' when allowed, else why not. Pure. */
function maint_can_cancel(array $r, int $now): string
{
    $s = maint_state($r, $now);
    return ($s === 'upcoming' || $s === 'announced') ? '' : ($s === 'offline' ? 'This window is running: use End now.' : 'This window is already over.');
}

// ── Database ───────────────────────────────────────────────────────────────

/** The generic message: the one saved on the Maintenance page, else the built-in text. */
function maint_default_message(PDO $pdo): string
{
    try {
        $st = $pdo->prepare('SELECT setting_value FROM cp_settings WHERE setting_key = ?');
        $st->execute([MAINT_SETTING]);
        $v = $st->fetchColumn();
        if (is_string($v) && trim($v) !== '') {
            return $v;
        }
    } catch (Throwable $e) {
        error_log('maint_default_message: ' . $e->getMessage());
    }
    return maint_builtin_message();
}

/** Is a generic message of the owner's own saved (rather than the built-in one)? */
function maint_default_is_custom(PDO $pdo): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM cp_settings WHERE setting_key = ?');
    $st->execute([MAINT_SETTING]);
    return (int)$st->fetchColumn() > 0;
}

/** Saves the generic message; null (or the built-in text) reverts to the built-in one. Returns '' or the error. Audited. */
function maint_save_default_message(PDO $pdo, ?string $msg, int $adminId): string
{
    $before = maint_default_message($pdo);
    if ($msg !== null) {
        $msg = maint_clean_message($msg);
        if ($msg === '') {
            return 'Write the message, or use "Revert to the generic message".';
        }
        if (mb_strlen($msg) > MAINT_MESSAGE_MAX) {
            return 'The message can be at most ' . MAINT_MESSAGE_MAX . ' characters.';
        }
        if ($msg !== strip_tags($msg)) {
            return 'Write the message as plain text (no HTML).';
        }
        if ($msg === maint_builtin_message()) {
            $msg = null;
        }
    }
    if ($msg === null) {
        $pdo->prepare('DELETE FROM cp_settings WHERE setting_key = ?')->execute([MAINT_SETTING]);
    } else {
        $pdo->prepare('INSERT INTO cp_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([MAINT_SETTING, $msg]);
    }
    if (function_exists('billing_settings_cache')) {
        billing_settings_cache(null, true);
    }
    audit_log($pdo, $adminId, $msg === null ? 'maintenance_message_reverted' : 'maintenance_message_saved', null,
        ['message' => $before], ['message' => $msg ?? maint_builtin_message()]);
    return '';
}

function maint_find(PDO $pdo, int $id, bool $forUpdate = false): ?array
{
    $st = $pdo->prepare('SELECT * FROM cp_maintenance WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

/** Planned windows of one business that are not over. */
function maint_open_rows(PDO $pdo, int $tenantId, int $now): array
{
    $st = $pdo->prepare("SELECT * FROM cp_maintenance WHERE tenant_id = ? AND state = 'planned' AND ends_at > ? ORDER BY starts_at, id");
    $st->execute([$tenantId, $now]);
    return $st->fetchAll();
}

/**
 * Schedules one window for each business in $tenantIds, all or none: when any of them already has a window at that time,
 * or no longer exists, nothing is saved. $clean comes from maint_validate(). @return array{0:string,1:int[]} [error, new ids]
 */
function maint_schedule(PDO $pdo, array $tenantIds, array $clean, int $adminId, int $now): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $tenantIds), static fn(int $i): bool => $i > 0)));
    sort($ids); // one lock order, so two saves at once never deadlock
    if (!$ids) {
        return ['Choose at least one business.', []];
    }
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $clash = [];
        $names = [];
        foreach ($ids as $tid) {
            $t = tenant_find($pdo, $tid, true);
            if ($t === null) {
                throw new DomainException('gone');
            }
            $names[$tid] = (string)$t['name'];
            if (maint_overlaps(maint_open_rows($pdo, $tid, $now), $clean['starts_at'], $clean['ends_at'])) {
                $clash[] = (string)$t['name'];
            }
        }
        if ($clash) {
            if ($own) {
                $pdo->rollBack();
            }
            return ['Nothing was saved: ' . implode(', ', $clash) . (count($clash) === 1 ? ' already has' : ' already have')
                . ' a maintenance window at that time. End or cancel it first, or pick another time.', []];
        }
        $ins = $pdo->prepare('INSERT INTO cp_maintenance (tenant_id, scope, announce_from, starts_at, ends_at, message, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $new = [];
        foreach ($ids as $tid) {
            $ins->execute([$tid, $clean['scope'], $clean['announce_from'], $clean['starts_at'], $clean['ends_at'], $clean['message'], $adminId ?: null]);
            $id = (int)$pdo->lastInsertId();
            $new[] = $id;
            audit_log($pdo, $adminId ?: null, 'maintenance_scheduled', $tid, null, ['id' => $id, 'business' => $names[$tid], 'scope' => $clean['scope'],
                'announce_from' => date('Y-m-d H:i', $clean['announce_from']), 'starts_at' => date('Y-m-d H:i', $clean['starts_at']),
                'ends_at' => date('Y-m-d H:i', $clean['ends_at']), 'message' => $clean['message'] ?? '(the generic message)']);
        }
        if ($own) {
            $pdo->commit();
        }
        return ['', $new];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof DomainException) {
            return ['Nothing was saved: one of the chosen businesses no longer exists. Reload the page.', []];
        }
        throw $e;
    }
}

/**
 * End now / Cancel / Use the generic message on one window. $what: end | cancel | generic.
 * @return array{0:string,1:int} [error, the business id]
 */
function maint_change(PDO $pdo, int $id, string $what, int $adminId, int $now): array
{
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $r = maint_find($pdo, $id, true);
        if ($r === null) {
            throw new DomainException('That window no longer exists.');
        }
        if ($what === 'end') {
            $why = maint_can_end($r, $now);
            $sql = "UPDATE cp_maintenance SET state = 'ended', ends_at = ?, ended_at = ? WHERE id = ?";
            $args = [$now, $now, $id];
            $action = 'maintenance_ended';
        } elseif ($what === 'cancel') {
            $why = maint_can_cancel($r, $now);
            $sql = "UPDATE cp_maintenance SET state = 'cancelled', ended_at = ? WHERE id = ?";
            $args = [$now, $id];
            $action = 'maintenance_cancelled';
        } elseif ($what === 'generic') {
            $s = maint_state($r, $now);
            $why = in_array($s, ['upcoming', 'announced', 'offline'], true)
                ? ($r['message'] === null ? 'This window already uses the generic message.' : '') : 'This window is already over.';
            $sql = 'UPDATE cp_maintenance SET message = NULL WHERE id = ?';
            $args = [$id];
            $action = 'maintenance_message_generic';
        } else {
            throw new DomainException('Unknown action.');
        }
        if ($why !== '') {
            throw new DomainException($why);
        }
        $pdo->prepare($sql)->execute($args);
        audit_log($pdo, $adminId ?: null, $action, (int)$r['tenant_id'], $r, maint_find($pdo, $id));
        if ($own) {
            $pdo->commit();
        }
        return ['', (int)$r['tenant_id']];
    } catch (DomainException $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [$e->getMessage(), 0];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * For the licence answer: the window to send this business (or null), marking when it first went out. Throws on a database
 * fault (the caller keeps the licence answer going without it).
 */
function maint_answer_block(PDO $pdo, int $tenantId, int $now): ?array
{
    $r = maint_pick(maint_open_rows($pdo, $tenantId, $now), $now);
    if ($r === null) {
        return null;
    }
    if ($r['sent_at'] === null) {
        $pdo->prepare('UPDATE cp_maintenance SET sent_at = ? WHERE id = ? AND sent_at IS NULL')->execute([$now, (int)$r['id']]);
    }
    return maint_block($r, maint_default_message($pdo));
}

/** The business says which window it holds (its check-in's maintenance_seen): the first time is kept. */
function maint_store_seen(PDO $pdo, int $tenantId, int $seenId, int $now): void
{
    if ($seenId <= 0) {
        return;
    }
    $pdo->prepare('UPDATE cp_maintenance SET hotel_seen_at = ? WHERE id = ? AND tenant_id = ? AND hotel_seen_at IS NULL')
        ->execute([$now, $seenId, $tenantId]);
}

/** Is migration 014 applied? */
function maint_table_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM cp_maintenance LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
