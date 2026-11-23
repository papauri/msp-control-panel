<?php
// 2026-11-09 backups + users, no database: encrypted panel backups, the business backup report (what is accepted),
// the per-business status words, the state file lock, passwords and session fingerprints.
require __DIR__ . '/_harness.php';
require __DIR__ . '/../../app/bootstrap.php'; // functions only: nothing here connects to the database

$a = signing_generate_keypair();
$b = signing_generate_keypair();
if ($a === null || $b === null) {
    echo "FAIL: openssl cannot generate P-256 keys here\n";
    exit(1);
}

// ── Keys: one per use ──
$kBackup = keys_derive('cp-backup-v1', $a['private']);
t_ok('backup key is 32 bytes', is_string($kBackup) && strlen($kBackup) === 32);
t_ok('backup key differs from the 2-step key (own label)', $kBackup !== keys_secret_box_key($a['private']));
t_ok('backup key differs per signing key', $kBackup !== keys_derive('cp-backup-v1', $b['private']));

// ── Panel backup encryption ──
$sql = "INSERT INTO cp_tenants VALUES (1, 'x');\n" . str_repeat('-- filler', 500);
$gz = gzencode($sql, 6);
$blob = backup_encrypt($gz, $kBackup);
t_ok('encrypted file starts with its format header', is_string($blob) && strpos($blob, BACKUP_MAGIC) === 0);
t_ok('the encrypted file does not contain the data', is_string($blob) && strpos($blob, 'cp_tenants') === false);
t_eq('decrypt + gunzip gives the SQL back', $sql, gzdecode((string)backup_decrypt((string)$blob, $kBackup)));
t_eq('the wrong key cannot open it', null, backup_decrypt((string)$blob, keys_derive('cp-backup-v1', $b['private'])));
t_eq('no key cannot open it', null, backup_decrypt((string)$blob, null));
$bad = (string)$blob;
$bad[strlen($bad) - 1] = chr(ord($bad[strlen($bad) - 1]) ^ 1);
t_eq('a changed file is refused', null, backup_decrypt($bad, $kBackup));
t_eq('a file without the header is refused', null, backup_decrypt('XXXXX' . substr((string)$blob, 5), $kBackup));
t_eq('a short file is refused', null, backup_decrypt(BACKUP_MAGIC . 'abc', $kBackup));

// Retention: the newest backup of each of the newest 14 days (backup review 2026-11-23)
$mk = static fn(array $names): array => array_map(static fn($n) => ['name' => $n, 'size' => 1, 'at' => 0], $names);
$sameDay = [];
for ($i = 20; $i >= 1; $i--) {
    $sameDay[] = sprintf('panel-20261123-10%02d00.cpbk', $i); // 20 runs today, newest first
}
$older = [];
for ($d = 22; $d >= 1; $d--) {
    $older[] = sprintf('panel-202611%02d-020000.cpbk', $d);
}
$gone = backup_panel_prune_list($mk(array_merge($sameDay, $older)));
t_ok('many runs in one day keep only that day\'s newest', !in_array($sameDay[0], $gone, true) && count(array_intersect($sameDay, $gone)) === 19);
t_ok('older days survive a busy day', !in_array('panel-20261122-020000.cpbk', $gone, true) && !in_array('panel-20261110-020000.cpbk', $gone, true));
t_ok('the 15th newest day goes', in_array('panel-20261109-020000.cpbk', $gone, true) && in_array('panel-20261101-020000.cpbk', $gone, true));
t_eq('14 files kept in all', 14, count(array_merge($sameDay, $older)) - count($gone));
t_eq('nothing goes with fewer than 14 days', [], backup_panel_prune_list($mk(['panel-20261123-100000.cpbk', 'panel-20261122-100000.cpbk'])));
t_eq('no backup name outside the pattern opens (no path tricks)', null, backup_panel_open('../.env'));

// ── Streamed (chunked) compression: same file format as before, so older backups and new ones open the same way ──
$chunks = [];
for ($i = 0; $i < 2000; $i++) {
    $chunks[] = "INSERT INTO `cp_x` (`id`, `v`) VALUES ($i, '" . bin2hex(random_bytes(8)) . "');\n";
}
$chunks[] = '';
$streamed = backup_seal_stream(static function (callable $emit) use ($chunks): void {
    foreach ($chunks as $c) {
        $emit($c);
    }
}, $kBackup);
t_ok('streamed backup starts with the same format header', is_string($streamed) && strpos($streamed, BACKUP_MAGIC) === 0);
t_eq('streamed backup opens with the same decrypt + gunzip as before', implode('', $chunks), gzdecode((string)backup_decrypt((string)$streamed, $kBackup)));
t_ok('streamed backup is compressed (smaller than the SQL)', strlen((string)$streamed) < strlen(implode('', $chunks)));
t_eq('an older (one-piece gzencode) backup still opens', $sql, gzdecode((string)backup_decrypt((string)backup_encrypt((string)gzencode($sql, 6), $kBackup), $kBackup)));
$emptyRun = backup_seal_stream(static function (callable $emit): void {
}, $kBackup);
t_eq('an export with nothing in it still makes a valid file', '', gzdecode((string)backup_decrypt((string)$emptyRun, $kBackup)));
$threw = false;
try {
    backup_seal_stream(static function (callable $emit): void {
        $emit('a');
        throw new RuntimeException('db gone');
    }, $kBackup);
} catch (RuntimeException $e) {
    $threw = true;
}
t_ok('a failing export is passed up, never saved half done', $threw);

// ── Ending the response early ──
t_eq('the command line cannot detach from a browser', false, backup_can_detach());
t_eq('so ending the response says it did not detach (the slow part is skipped)', false, backup_finish_response());

// ── What a business may report ──
$now = 1800000000;
$r = backup_clean_report(['last_at' => $now - 3600, 'size' => 123456, 'error_at' => 0, 'error_code' => '', 'offsite_at' => $now - 86400, 'requested_seen' => $now - 100, 'path' => '/home/x/backups/db.sql.gz'], $now);
t_ok('a good report is kept', is_array($r) && $r['last_at'] === $now - 3600 && $r['size'] === 123456 && $r['offsite_at'] === $now - 86400);
t_ok('unknown fields (a path) are dropped', is_array($r) && !array_key_exists('path', $r));
$r2 = backup_clean_report(['last_at' => '2026-11-09', 'size' => -5, 'error_at' => $now + 999999, 'error_code' => 'Disk FULL; rm -rf', 'requested_seen' => 5], $now);
t_eq('text dates, negative sizes, far-future dates, odd codes and pre-2000 dates are all refused', null, $r2);
t_eq('not an object -> nothing', null, backup_clean_report('hello', $now));
t_eq('an error code is kept when it is a plain word', 'disk_full', backup_clean_report(['error_at' => $now - 10, 'error_code' => 'disk_full'], $now)['error_code'] ?? null);

// ── Status words per business ──
$v = static fn(array $e) => backup_tenant_view($e, $now);
t_eq('nothing reported', 'none', $v([])['state']);
t_eq('backed up 2 hours ago', 'ok', $v(['report' => ['last_at' => $now - 7200, 'reported_at' => $now]])['state']);
t_eq('last good backup 2 days ago = overdue', 'overdue', $v(['report' => ['last_at' => $now - 172800, 'reported_at' => $now]])['state']);
t_eq('an error after the last good backup = failed', 'failed', $v(['report' => ['last_at' => $now - 90000, 'error_at' => $now - 600, 'error_code' => 'disk_full', 'reported_at' => $now]])['state']);
t_ok('failed words name the reason', strpos($v(['report' => ['last_at' => 0, 'error_at' => $now - 600, 'error_code' => 'disk_full', 'reported_at' => $now]])['words'], 'disk full') !== false);
t_eq('asked and not yet seen = waiting', 'asked', $v(['requested_at' => $now - 60, 'report' => ['last_at' => $now - 90000, 'reported_at' => $now - 3000]])['state']);
t_eq('asked, seen, and the backup was fresh enough = ok', 'ok', $v(['requested_at' => $now - 60, 'report' => ['last_at' => $now - 3600, 'requested_seen' => $now - 60, 'reported_at' => $now]])['state']);
t_eq('an old unanswered request is not shown as waiting forever', 'overdue', $v(['requested_at' => $now - 90000, 'report' => ['last_at' => $now - 172800, 'reported_at' => $now]])['state']);

// ── State file: locked read-modify-write (the real file is put back afterwards) ──
$p = backup_state_path();
$was = is_file($p) ? (string)file_get_contents($p) : null;
try {
    @unlink($p);
    backup_state_update(static function (array &$s): void { $s['tenants']['7']['requested_at'] = 111; });
    backup_state_update(static function (array &$s): void { $s['tenants']['7']['report'] = ['last_at' => 5]; });
    t_eq('two updates keep each other\'s fields', 111, backup_requested_at(7));
    t_eq('report stored beside the request', 5, backup_state()['tenants']['7']['report']['last_at'] ?? null);
    $day = backup_claim_today();
    t_eq('claim today: first time gives today', date('Y-m-d'), $day);
    t_eq('claim today: second time no', null, backup_claim_today());
    backup_release_day((string)$day);
    t_eq('a released day can be claimed again (a failed run is retried)', date('Y-m-d'), backup_claim_today());
    backup_release_day('2000-01-01');
    t_eq('releasing another day never clears today\'s mark', date('Y-m-d'), backup_state()['auto_day'] ?? null);

    // A run that throws gives its claimed day back (no database: a PDO stand-in whose query() fails)
    backup_release_day(date('Y-m-d'));
    $day = backup_claim_today();
    $noDb = new class extends PDO {
        public function __construct()
        {
        }
    };
    $threw = false;
    $logWas = ini_set('error_log', sys_get_temp_dir() . '/cp-backup-test.log');
    try {
        backup_run_all($noDb, 'first_sign_in', null, $day);
    } catch (Throwable $e) {
        $threw = true;
    }
    ini_set('error_log', (string)$logWas);
    t_ok('the run threw (the stand-in has no database)', $threw);
    t_ok('a run that throws gives today back', !isset(backup_state()['auto_day']));
    // ... but a failed run never clears a day another (good) run marked
    backup_state_update(static function (array &$s): void { $s['auto_day'] = date('Y-m-d'); });
    try {
        backup_run_all($noDb, 'button', null, null);
    } catch (Throwable $e) {
    }
    t_eq('a failed button run leaves an earlier good run of today counted', date('Y-m-d'), backup_state()['auto_day'] ?? null);

    // Shared-lock read: works while another handle holds a shared lock, empty when the file is missing
    $fh = fopen($p, 'r');
    flock($fh, LOCK_SH);
    t_eq('state reads beside another reader', 111, backup_requested_at(7));
    flock($fh, LOCK_UN);
    fclose($fh);
    @unlink($p);
    t_eq('no state file -> empty state', [], backup_state());
    t_ok('a read never creates the state file', !is_file($p));
} finally {
    if ($was === null) {
        @unlink($p);
    } else {
        file_put_contents($p, $was);
    }
}

// ── Passwords and sessions ──
t_eq('a good password passes', '', auth_password_problem('correct horse battery', 'correct horse battery', 'owner@example.com'));
t_ok('different repeats are refused', auth_password_problem('correct horse battery', 'correct horse batterx') !== '');
t_ok('short passwords are refused', auth_password_problem('short', 'short') !== '');
t_ok('the email name inside the password is refused', auth_password_problem('ownerpassword123', 'ownerpassword123', 'owner@example.com') !== '');
$h = auth_hash_password('correct horse battery');
t_ok('hash is bcrypt cost 10 (same timing as the dummy hash)', strpos($h, '$2y$10$') === 0 && password_verify('correct horse battery', $h));
t_ok('session fingerprint changes when the password changes', auth_fp($h, 'S') !== auth_fp(auth_hash_password('another long pass'), 'S'));
t_ok('session fingerprint changes when the 2-step secret changes (reset)', auth_fp($h, 'S') !== auth_fp($h, ''));
t_ok('valid email accepted', users_valid_email('new.person@example.com'));
t_ok('not an email refused', !users_valid_email('new person'));
t_ok('a too long email refused', !users_valid_email(str_repeat('a', 190) . '@example.com'));

// ── Removing a user: only set-up admins count, and the acting admin must still be one ──
$setUp = ['totp_secret' => 'JBSWY3DPEHPK3PXP'];
$pending = ['totp_secret' => ''];
t_eq('set-up admin removes a set-up admin when two are set up', '', users_remove_problem($setUp, $setUp, 2));
t_ok('the last set-up admin is never removed', users_remove_problem($setUp, $setUp, 1) !== '');
t_eq('a pending account can be removed while one admin is set up', '', users_remove_problem($setUp, $pending, 1));
t_ok('an acting admin who was removed meanwhile cannot remove (two admins removing each other)', users_remove_problem(null, $setUp, 1) !== '');
t_ok('an acting admin who is not set up (reset meanwhile) cannot remove', users_remove_problem($pending, $setUp, 2) !== '');
t_eq('a user already gone', 'That user no longer exists.', users_remove_problem($setUp, null, 2));

// ── Setup session ──
$_SESSION = ['setup_admin_id' => 4, 'setup_email' => 'n@example.com', 'setup_at' => time(), 'setup_secret' => totp_generate_secret()];
t_ok('a fresh setup session is found', auth_setup_current() !== null && auth_setup_current()['id'] === 4);
t_ok('a setup session never counts as signed in', empty($_SESSION['admin_id']));
$_SESSION['setup_at'] = time() - AUTH_SETUP_SECONDS - 1;
t_eq('a setup session older than 15 minutes is refused', null, auth_setup_current());
$_SESSION = [];

t_done('backup_users');
