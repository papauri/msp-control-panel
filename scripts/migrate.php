<?php
// CLI only. Applies migrations/*.sql in order, once each. Applied files are recorded in
// cp_schema_migrations, so re-running skips them.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS cp_schema_migrations (
       filename VARCHAR(190) NOT NULL,
       applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (filename)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$done = [];
foreach ($pdo->query('SELECT filename FROM cp_schema_migrations')->fetchAll() as $r) {
    $done[$r['filename']] = true;
}

$files = glob(APP_ROOT . '/migrations/*.sql');
sort($files);
foreach ($files as $f) {
    $name = basename($f);
    if (isset($done[$name])) {
        echo 'Skipped ' . $name . " (already applied)\n";
        continue;
    }
    $sql = file_get_contents($f);
    $sql = preg_replace('/^\s*--.*$/m', '', (string)$sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            // 1060 duplicate column / 1061 duplicate key: this statement already ran on this database
            // (e.g. it was applied before migrations were recorded). Anything else is a real failure.
            $errno = (int)($e->errorInfo[1] ?? 0);
            if ($errno !== 1060 && $errno !== 1061) {
                throw $e;
            }
            echo '  (' . $name . ': already present, continuing)' . "\n";
        }
    }
    $pdo->prepare('INSERT INTO cp_schema_migrations (filename) VALUES (?)')->execute([$name]);
    echo 'Applied ' . $name . "\n";
}
