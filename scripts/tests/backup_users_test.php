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
t_eq('no backup name outside the pattern opens (no path tricks)', null, backup_panel_open('../.env'));

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
    t_ok('claim today: first time yes', backup_claim_today());
    t_ok('claim today: second time no', !backup_claim_today());
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

// ── Setup session ──
$_SESSION = ['setup_admin_id' => 4, 'setup_email' => 'n@example.com', 'setup_at' => time(), 'setup_secret' => totp_generate_secret()];
t_ok('a fresh setup session is found', auth_setup_current() !== null && auth_setup_current()['id'] === 4);
t_ok('a setup session never counts as signed in', empty($_SESSION['admin_id']));
$_SESSION['setup_at'] = time() - AUTH_SETUP_SECONDS - 1;
t_eq('a setup session older than 15 minutes is refused', null, auth_setup_current());
$_SESSION = [];

t_done('backup_users');
