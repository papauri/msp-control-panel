<?php
// CLI only. Writes an offline SQL export of the control panel's own tables (every cp_* table, app/dbexport.php) to
// database/cp-export-YYYYMMDD-HHMMSS.sql: CREATE TABLE IF NOT EXISTS + INSERTs, ready to import
// into the panel's own database later (phpMyAdmin > Import, or mysql < file).
// Read-only on the source database. database/ is git-ignored: exports hold password hashes,
// TOTP secrets and API-key hashes, so they never go to GitHub.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$dir = APP_ROOT . '/database';
if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
    fwrite(STDERR, "Cannot create database/ folder
");
    exit(1);
}
$file = $dir . '/cp-export-' . date('Ymd-His') . '.sql';
$old = umask(0077);
$fh = fopen($file, 'xb');
umask($old);
if ($fh === false) {
    fwrite(STDERR, "Cannot write $file
");
    exit(1);
}
$ok = true;
$res = dbexport_write($pdo, static function (string $chunk) use ($fh, &$ok): void {
    $ok = $ok && fwrite($fh, $chunk) === strlen($chunk);
});
fclose($fh);
if (!$ok) {
    @unlink($file);
    fwrite(STDERR, "Cannot write $file (disk full?)
");
    exit(1);
}
@chmod($file, 0600);
echo 'Exported ' . $res['rows'] . ' row(s) from ' . $res['tables'] . ' table(s) to ' . $file . "
";
