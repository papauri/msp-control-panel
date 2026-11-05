<?php
// CLI only. Applies migrations/*.sql in order. Migrations only use CREATE TABLE IF NOT EXISTS,
// so re-running is safe.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$files = glob(APP_ROOT . '/migrations/*.sql');
sort($files);
foreach ($files as $f) {
    $sql = file_get_contents($f);
    $sql = preg_replace('/^\s*--.*$/m', '', (string)$sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
    echo 'Applied ' . basename($f) . "\n";
}
