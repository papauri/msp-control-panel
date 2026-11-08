<?php
// Backups (owner 2026-11-09): "back up our hotel and other businesses once a day on first login, a cron job, and one button".
//
// Data protection: each business backs ITSELF up on its own server (its existing backup engine, rotation and off-site
// download). The panel only ASKS for a backup (a signed field in the business's next licence answer) and shows the
// result each business reports back (when, size, ok or the error code). No client data ever reaches the panel.
// The panel's own records (cp_ tables) are backed up here, encrypted (AES-256-GCM, key derived from the signing key
// under its own label), outside public/, newest BACKUP_KEEP kept.
//
// State (requests, reports, the daily auto-run day) lives in storage/backup-state.json: no database change.

const BACKUP_KEEP = 14;                 // the panel's own encrypted backups kept (one a day = two weeks)
const BACKUP_MAGIC = 'CPBK1';           // file header: format version
const BACKUP_REPORT_MAX_AGE = 129600;   // 36 h: a business whose last good backup is older is shown as overdue

function backup_dir(): string
{
    $dir = APP_ROOT . '/storage/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && !file_exists($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return $dir;
}

// ── State file (locked read-modify-write) ──────────────────────────────────

function backup_state_path(): string
{
    $dir = APP_ROOT . '/storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir . '/backup-state.json';
}

/** Read-only snapshot of the state. */
function backup_state(): array
{
    $p = backup_state_path();
    $raw = is_readable($p) ? (string)@file_get_contents($p) : '';
    $s = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($s) ? $s : [];
}

/**
 * Changes the state under an exclusive lock: $fn receives the current state by reference and may return a value,
 * which is passed back. Throws RuntimeException when the file cannot be opened or written.
 */
function backup_state_update(callable $fn)
{
    $p = backup_state_path();
    $old = umask(0077);
    $fh = @fopen($p, 'c+');
    umask($old);
    if ($fh === false) {
        throw new RuntimeException('Cannot open ' . $p);
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            throw new RuntimeException('Cannot lock ' . $p);
        }
        $raw = stream_get_contents($fh);
        $s = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $s = is_array($s) ? $s : [];
        $ret = $fn($s);
        $json = json_encode($s, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json) || !ftruncate($fh, 0) || !rewind($fh) || fwrite($fh, $json) !== strlen($json)) {
            throw new RuntimeException('Cannot write ' . $p);
        }
        fflush($fh);
        flock($fh, LOCK_UN);
        return $ret;
    } finally {
        fclose($fh);
    }
}

// ── Businesses: ask, and record what they report ───────────────────────────

/** Asks one business (or every business when $tenantId is null) to back up at its next check-in. Returns the ids asked. */
function backup_request(PDO $pdo, ?int $tenantId = null): array
{
    if ($tenantId !== null) {
        $ids = tenant_find($pdo, $tenantId) ? [$tenantId] : [];
    } else {
        $ids = array_map('intval', $pdo->query('SELECT id FROM cp_tenants ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }
    if (!$ids) {
        return [];
    }
    $now = time();
    backup_state_update(static function (array &$s) use ($ids, $now): void {
        foreach ($ids as $id) {
            $s['tenants'][(string)$id]['requested_at'] = $now;
        }
    });
    return $ids;
}

/** The time of the latest backup request for this business (0 = none). Goes into its signed licence answer. */
function backup_requested_at(int $tenantId): int
{
    return (int)(backup_state()['tenants'][(string)$tenantId]['requested_at'] ?? 0);
}

/**
 * Cleans what a business reports in its check-in ({"backup": {...}}). Unknown keys are dropped; nothing is trusted
 * beyond its type and range. Null when there is nothing usable.
 */
function backup_clean_report($in, int $now): ?array
{
    if (!is_array($in)) {
        return null;
    }
    $t = static function ($v) use ($now): int {
        return (is_int($v) && $v > 946684800 && $v <= $now + 86400) ? $v : 0; // after 2000, not more than a day ahead
    };
    $out = [
        'last_at'      => $t($in['last_at'] ?? null),
        'size'         => (is_int($in['size'] ?? null) && $in['size'] >= 0 && $in['size'] < 1099511627776) ? $in['size'] : 0,
        'error_at'     => $t($in['error_at'] ?? null),
        'error_code'   => (is_string($in['error_code'] ?? null) && preg_match('/^[a-z_]{1,30}$/D', $in['error_code'])) ? $in['error_code'] : '',
        'offsite_at'   => $t($in['offsite_at'] ?? null),
        'requested_seen' => $t($in['requested_seen'] ?? null),
        'reported_at'  => $now,
    ];
    return ($out['last_at'] || $out['error_at'] || $out['requested_seen']) ? $out : null;
}

function backup_store_report(int $tenantId, array $report): void
{
    backup_state_update(static function (array &$s) use ($tenantId, $report): void {
        $s['tenants'][(string)$tenantId]['report'] = $report;
    });
}

/**
 * One business's backup state for the screens: ['state' => ok|overdue|failed|asked|none, 'words' => ..., + report].
 * Pure: $now and the stored entry are passed in.
 */
function backup_tenant_view(array $entry, int $now): array
{
    $r = is_array($entry['report'] ?? null) ? $entry['report'] : [];
    $req = (int)($entry['requested_at'] ?? 0);
    $last = (int)($r['last_at'] ?? 0);
    $errAt = (int)($r['error_at'] ?? 0);
    $v = ['requested_at' => $req, 'last_at' => $last, 'size' => (int)($r['size'] ?? 0), 'offsite_at' => (int)($r['offsite_at'] ?? 0),
        'reported_at' => (int)($r['reported_at'] ?? 0), 'error_code' => (string)($r['error_code'] ?? '')];
    if ($errAt > $last) {
        return $v + ['state' => 'failed', 'words' => 'Last backup failed' . ($v['error_code'] !== '' ? ' (' . str_replace('_', ' ', $v['error_code']) . ')' : '')];
    }
    if ($req > 0 && $req > $last && $req > (int)($r['requested_seen'] ?? 0) && $now - $req < 7200) {
        return $v + ['state' => 'asked', 'words' => 'Asked; waiting for the business to check in (within the hour)'];
    }
    if ($last === 0) {
        return $v + ['state' => 'none', 'words' => $v['reported_at'] ? 'No backup reported yet' : 'Nothing reported yet (the business has not checked in since backups were added)'];
    }
    if ($now - $last > BACKUP_REPORT_MAX_AGE) {
        return $v + ['state' => 'overdue', 'words' => 'Last good backup is more than 36 hours old'];
    }
    return $v + ['state' => 'ok', 'words' => 'Backed up'];
}

// ── The panel's own records, encrypted ─────────────────────────────────────

function backup_encrypt(string $plain, string $key): ?string
{
    if (strlen($key) !== 32) {
        return null;
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, BACKUP_MAGIC, 16);
    return ($ct === false || strlen($tag) !== 16) ? null : BACKUP_MAGIC . $nonce . $tag . $ct;
}

function backup_decrypt(string $blob, ?string $key): ?string
{
    $m = strlen(BACKUP_MAGIC);
    if ($key === null || strlen($key) !== 32 || strlen($blob) < $m + 28 || substr($blob, 0, $m) !== BACKUP_MAGIC) {
        return null;
    }
    $plain = openssl_decrypt(substr($blob, $m + 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($blob, $m, 12), substr($blob, $m + 12, 16), BACKUP_MAGIC);
    return $plain === false ? null : $plain;
}

/** @return array<int,array{name:string,size:int,at:int}> the panel's own backups, newest first */
function backup_panel_list(): array
{
    $out = [];
    foreach ((array)glob(backup_dir() . '/panel-*.cpbk') as $f) {
        $name = basename((string)$f);
        if (preg_match('/^panel-\d{8}-\d{6}\.cpbk$/D', $name)) {
            $out[] = ['name' => $name, 'size' => (int)@filesize($f), 'at' => (int)@filemtime($f)];
        }
    }
    usort($out, static fn($a, $b) => strcmp($b['name'], $a['name']));
    return $out;
}

/**
 * Backs up the panel's own records (every cp_ table) to an encrypted file. Never stores them unencrypted: with no
 * signing key it refuses. Keeps the newest BACKUP_KEEP. @return array{ok:bool,name:string,size:int,error:string}
 */
function backup_panel_run(PDO $pdo): array
{
    $key = keys_derive('cp-backup-v1');
    if ($key === null) {
        return ['ok' => false, 'name' => '', 'size' => 0, 'error' => 'There is no signing key yet, so the panel cannot encrypt its backup (create it on the Signing key page).'];
    }
    try {
        $sql = '';
        dbexport_write($pdo, static function (string $chunk) use (&$sql): void {
            $sql .= $chunk;
        });
        $gz = gzencode($sql, 6);
        $blob = $gz === false ? null : backup_encrypt($gz, $key);
        unset($sql, $gz);
        if ($blob === null || backup_decrypt($blob, $key) === null) {
            return ['ok' => false, 'name' => '', 'size' => 0, 'error' => 'The backup could not be encrypted.'];
        }
        $dir = backup_dir();
        $name = 'panel-' . date('Ymd-His') . '.cpbk';
        $old = umask(0077);
        $okW = @file_put_contents($dir . '/' . $name . '.tmp', $blob, LOCK_EX) === strlen($blob) && @rename($dir . '/' . $name . '.tmp', $dir . '/' . $name);
        umask($old);
        if (!$okW) {
            @unlink($dir . '/' . $name . '.tmp');
            return ['ok' => false, 'name' => '', 'size' => 0, 'error' => 'The backup could not be written (disk full or no permission on storage/backups).'];
        }
        foreach (array_slice(backup_panel_list(), BACKUP_KEEP) as $gone) {
            @unlink($dir . '/' . $gone['name']);
        }
        return ['ok' => true, 'name' => $name, 'size' => strlen($blob), 'error' => ''];
    } catch (Throwable $e) {
        error_log('backup_panel_run: ' . $e->getMessage());
        return ['ok' => false, 'name' => '', 'size' => 0, 'error' => 'The backup failed: the database could not be read.'];
    }
}

/** The SQL inside one of the panel's own backups, or null (missing, changed, or the signing key cannot open it). */
function backup_panel_open(string $name): ?string
{
    if (!preg_match('/^panel-\d{8}-\d{6}\.cpbk$/D', $name)) {
        return null;
    }
    $f = backup_dir() . '/' . $name;
    $blob = is_file($f) ? @file_get_contents($f) : false;
    $gz = $blob === false ? null : backup_decrypt((string)$blob, keys_derive('cp-backup-v1'));
    $sql = $gz === null ? false : @gzdecode($gz);
    return $sql === false ? null : $sql;
}

// ── One run: the panel's records + ask every business ──────────────────────

/**
 * The daily / one-button / cron run: backs up the panel's own records and asks every business to back up.
 * $why: 'button' | 'first_sign_in' | 'cron'. Nudging the businesses (so they check in now) is left to the caller,
 * after its response has been sent. @return array{panel:array,asked:int[]}
 */
function backup_run_all(PDO $pdo, string $why, ?int $adminId): array
{
    $panel = backup_panel_run($pdo);
    $asked = backup_request($pdo, null);
    backup_state_update(static function (array &$s) use ($panel, $why): void {
        $s['last_run'] = ['at' => time(), 'why' => $why, 'panel_ok' => $panel['ok'], 'panel_error' => $panel['error']];
        $s['auto_day'] = date('Y-m-d'); // today's run is done, whoever started it: the first sign-in does not repeat it
    });
    audit_log($pdo, $adminId, 'backup_run', null, null, ['why' => $why, 'panel_backup' => $panel['ok'] ? $panel['name'] : ('failed: ' . $panel['error']), 'businesses_asked' => count($asked)]);
    return ['panel' => $panel, 'asked' => $asked];
}

/** True once per calendar day (panel time zone): claims today's automatic run. */
function backup_claim_today(): bool
{
    $today = date('Y-m-d');
    return (bool)backup_state_update(static function (array &$s) use ($today): bool {
        if (($s['auto_day'] ?? '') === $today) {
            return false;
        }
        $s['auto_day'] = $today;
        return true;
    });
}

/** Nudges each business so it checks in now and sees the request (each call waits at most 4 s). */
function backup_nudge_all(PDO $pdo, array $ids): void
{
    @set_time_limit(max(30, 6 * count($ids) + 10));
    foreach ($ids as $id) {
        tenant_nudge($pdo, (int)$id);
    }
}

/** Ends the response to the browser so slow work afterwards never makes the person wait (same as back_to in tenant.php). */
function backup_finish_response(): void
{
    @ignore_user_abort(true);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } else {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }
}
