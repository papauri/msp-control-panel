<?php
// CLI only. Writes an offline SQL export of the control panel's own tables (cp_*) to
// database/cp-export-YYYYMMDD-HHMMSS.sql: CREATE TABLE IF NOT EXISTS + INSERTs, ready to import
// into the panel's own database later (phpMyAdmin > Import, or mysql < file).
// Read-only on the source database. database/ is git-ignored: exports hold password hashes,
// TOTP secrets and API-key hashes, so they never go to GitHub.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

const EXPORT_TABLES = ['cp_admins', 'cp_tenants', 'cp_payments', 'cp_licence_checks', 'cp_audit_log', 'cp_rate_limits'];

$pdo = db();
$dir = APP_ROOT . '/database';
if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
    fwrite(STDERR, "Cannot create database/ folder\n");
    exit(1);
}

$lines = [];
$lines[] = '-- MSP control panel export, ' . gmdate('Y-m-d H:i:s') . ' UTC';
$lines[] = '-- Import into the panel\'s own database, then point .env DB_* at it.';
$lines[] = 'SET NAMES utf8mb4;';
$lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
$lines[] = '';

$total = 0;
foreach (EXPORT_TABLES as $table) {
    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $exists->execute([$table]);
    if ((int)$exists->fetchColumn() === 0) {
        $lines[] = "-- $table: not present in the source database (skipped)";
        continue;
    }
    $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
    $ddl = preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', (string)$create[1]);
    $ddl = preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl);
    $lines[] = "-- $table";
    $lines[] = $ddl . ';';

    $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $cols = implode(', ', array_map(function ($c) { return '`' . str_replace('`', '``', $c) . '`'; }, array_keys($row)));
        $vals = implode(', ', array_map(function ($v) use ($pdo) {
            return $v === null ? 'NULL' : $pdo->quote((string)$v);
        }, array_values($row)));
        $lines[] = "INSERT INTO `$table` ($cols) VALUES ($vals);";
    }
    $lines[] = "-- $table: " . count($rows) . ' row(s)';
    $lines[] = '';
    $total += count($rows);
}
$lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';

$file = $dir . '/cp-export-' . date('Ymd-His') . '.sql';
if (file_put_contents($file, implode("\n", $lines) . "\n") === false) {
    fwrite(STDERR, "Cannot write $file\n");
    exit(1);
}
@chmod($file, 0600);
echo 'Exported ' . $total . ' row(s) to ' . $file . "\n";
