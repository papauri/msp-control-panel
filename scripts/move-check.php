<?php
// CLI only. Run on the NEW server after moving the panel to its own database and domain (MOVING.md), before telling
// the businesses the new address. Read-only: it changes nothing. Exit code 0 = ready, 1 = something must be fixed.
//   php scripts/move-check.php            checks everything
//   php scripts/move-check.php --smtp     also logs in to the mail server (sends nothing)
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

$fail = 0;
$warn = 0;
$ok = static function (string $m): void { echo "OK    $m\n"; };
$bad = static function (string $m) use (&$fail): void { $fail++; echo "FIX   $m\n"; };
$note = static function (string $m) use (&$warn): void { $warn++; echo "NOTE  $m\n"; };

echo "MSP panel move check - " . date('Y-m-d H:i') . "\n\n";

// 1. PHP
version_compare(PHP_VERSION, '7.4.0', '>=') ? $ok('PHP ' . PHP_VERSION) : $bad('PHP ' . PHP_VERSION . ' is too old (7.4 or newer)');
foreach (['pdo_mysql', 'openssl', 'zlib', 'mbstring', 'json'] as $ext) {
    extension_loaded($ext) ? $ok("PHP extension $ext") : $bad("PHP extension $ext is missing (cPanel > Select PHP Version > Extensions)");
}
extension_loaded('curl') ? $ok('PHP extension curl (instant nudges to businesses)') : $note('PHP extension curl is missing: businesses still check in hourly, but not instantly');

// 2. Settings file
$appUrl = (string)env('APP_URL', '');
strpos($appUrl, 'https://') === 0 ? $ok("APP_URL $appUrl") : $bad('APP_URL in .env must be the new https:// address of the panel');
if (strpos($appUrl, 'cpanel.site') !== false || strpos($appUrl, 'example.com') !== false) {
    $note('APP_URL still looks temporary: ' . $appUrl);
}
if ((string)env('SMTP_HOST', '') !== '') {
    $ok('Email settings are in .env (SMTP_HOST ' . env('SMTP_HOST') . ', user ' . env('SMTP_USER', '') . ')');
} else {
    $bad('SMTP_HOST / SMTP_PORT / SMTP_USER / SMTP_PASS / SMTP_SECURE are empty in .env: the panel would borrow a hotel database\'s mail settings, which the new database does not have');
}
if ((string)env('MAIL_SETTINGS_FROM_DB', '') === '1') {
    $note('MAIL_SETTINGS_FROM_DB=1: set it to 0 (or remove it) once SMTP_* are filled in');
}
if (trim((string)env('MAIL_TEST_TO', '')) !== '') {
    $note('MAIL_TEST_TO is set: every email goes only to ' . env('MAIL_TEST_TO') . '. Remove the line to email clients for real');
}

// 3. Database
try {
    $pdo = db();
    $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $ok("Database connection ($dbName)");
} catch (Throwable $e) {
    $bad('Cannot connect to the database: check DB_HOST / DB_NAME / DB_USER / DB_PASS in .env');
    echo "\n$fail to fix.\n";
    exit(1);
}
$have = array_flip(dbexport_tables($pdo));
$want = [];
foreach (glob(APP_ROOT . '/migrations/*.sql') ?: [] as $f) {
    if (preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?(cp_[a-z0-9_]+)`?/i', (string)file_get_contents($f), $m)) {
        foreach ($m[1] as $t) {
            $want[strtolower($t)] = true;
        }
    }
}
$missing = array_diff(array_keys($want), array_keys($have));
$missing ? $bad('Tables missing: ' . implode(', ', $missing) . ' (import the panel database file, or run php scripts/migrate.php)')
    : $ok(count($want) . ' panel tables present');
$applied = array_flip($pdo->query('SELECT filename FROM cp_schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
$files = array_map('basename', glob(APP_ROOT . '/migrations/*.sql') ?: []);
$notApplied = array_diff($files, array_keys($applied));
$notApplied ? $bad('Migrations not recorded as applied: ' . implode(', ', $notApplied) . ' (run php scripts/migrate.php)') : $ok('All ' . count($files) . ' migrations applied');
$counts = [];
foreach (['cp_tenants' => 'businesses', 'cp_clients' => 'clients', 'cp_documents' => 'invoices/quotes', 'cp_payments' => 'payments', 'cp_admins' => 'users'] as $t => $label) {
    if (isset($have[$t])) {
        $counts[] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() . " $label";
    }
}
$ok('Records: ' . implode(', ', $counts) . ' (compare with the old panel before switching)');
$hotel = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('site_settings','bookings','rooms')")->fetchColumn();
$hotel > 0 ? $note('This database still holds hotel tables: the panel is not on a database of its own yet') : $ok('The database holds only the panel');

// 4. Signing key (it also opens the 2-step secrets and the panel's own backups)
$pub = keys_public_pem();
if ($pub === null) {
    $bad('Signing key not readable at ' . keys_private_path() . ' (copy keys/licence-private.pem from the old server; never make a new one)');
} else {
    $ok('Signing key readable; public key fingerprint ' . substr(hash('sha256', preg_replace('/\s+/', '', $pub)), 0, 16)
        . ' (must match the old panel: php scripts/move-check.php there shows the same)');
    $box = keys_secret_box_key();
    $admins = $pdo->query("SELECT email, totp_secret FROM cp_admins WHERE totp_secret <> ''")->fetchAll();
    $open = 0;
    foreach ($admins as $a) {
        $open += totp_open((string)$a['totp_secret'], totp_secret_is_sealed((string)$a['totp_secret']) ? $box : null) !== null ? 1 : 0;
    }
    $open === count($admins) ? $ok("All $open users' 6-digit codes open with this key")
        : $bad(($open) . ' of ' . count($admins) . " users' codes open with this key: the key is not the one from the old server");
}

// 5. Folders
foreach (['storage', 'storage/backups', 'logs'] as $d) {
    $p = APP_ROOT . '/' . $d;
    if (!is_dir($p)) {
        @mkdir($p, 0700, true);
    }
    is_dir($p) && is_writable($p) ? $ok("$d/ writable") : $bad("$d/ is not writable by PHP");
}
is_dir(APP_ROOT . '/lib/tcpdf') && is_dir(APP_ROOT . '/lib/phpmailer') ? $ok('PDF and email libraries present (lib/)') : $bad('lib/tcpdf or lib/phpmailer is missing: upload the whole folder');

// 6. Mail server login (optional)
if (in_array('--smtp', $argv ?? [], true)) {
    try {
        $c = mail_config(true);
        mail_load_phpmailer();
        $m = new PHPMailer\PHPMailer\PHPMailer(true);
        $m->isSMTP();
        $m->Host = $c['host'];
        $m->Port = $c['port'];
        $m->SMTPAuth = true;
        $m->Username = $c['user'];
        $m->Password = $c['pass'];
        $m->SMTPSecure = $c['secure'];
        $m->Timeout = 15;
        $m->smtpConnect() ? $ok('Mail server login works (' . $c['host'] . ')') : $bad('Mail server login failed');
        $m->smtpClose();
    } catch (Throwable $e) {
        $bad('Mail server login failed: check SMTP_* in .env');
    }
}

// 7. What to set next
$root = APP_ROOT;
$base = rtrim($appUrl, '/');
echo "\nCron jobs (cPanel > Cron Jobs, once a day each):\n"
    . "  30 2 * * * cd $root && php scripts/backup-all.php >> logs/backup-cron.log 2>&1\n"
    . "  0 8 * * *  cd $root && php scripts/billing-daily.php >> logs/billing-daily.log 2>&1\n"
    . "\nEach business's .env (its LICENCE_URL line):\n  LICENCE_URL=$base/api/licence.php\n";
echo "\n" . ($fail ? "$fail to fix" : 'Ready') . ($warn ? ", $warn note(s)" : '') . ".\n";
exit($fail ? 1 : 0);
