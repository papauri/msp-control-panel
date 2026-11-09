<?php
// The panel's own database as plain SQL (2026-11-09): every table whose name starts with cp_, found at run time so a
// new table is never left out of a move. Used by scripts/export-db.php (file), the Backups page (download, after
// password + fresh code) and the panel's own encrypted backups (backup.php). Read-only on the database.
// Output: CREATE TABLE IF NOT EXISTS + INSERT rows, importable in phpMyAdmin or with mysql < file.

/**
 * Tables whose rows are not worth moving (they rebuild themselves); their structure is still exported.
 * cp_password_resets: links that last 30 minutes; never worth keeping in a backup or a move.
 */
const DBEXPORT_SKIP_ROWS = ['cp_rate_limits', 'cp_password_resets'];

/** @return string[] the cp_ tables in this database, sorted */
function dbexport_tables(PDO $pdo): array
{
    $t = $pdo->query("SHOW TABLES LIKE 'cp\_%'")->fetchAll(PDO::FETCH_COLUMN);
    $t = array_values(array_filter(array_map('strval', $t), static fn($n) => preg_match('/^cp_[a-z0-9_]+$/D', $n) === 1));
    sort($t);
    return $t;
}

/**
 * Streams the export through $emit (one chunk of SQL text per call). Rows are read one at a time.
 * @return array{tables:int,rows:int}
 */
function dbexport_write(PDO $pdo, callable $emit): array
{
    $tables = dbexport_tables($pdo);
    $emit('-- MSP control panel database, ' . gmdate('Y-m-d H:i:s') . " UTC\n"
        . "-- Import into the panel's own database (phpMyAdmin > Import), then point DB_* in the panel's .env at it.\n"
        . "-- Holds password hashes, encrypted 2-step secrets and licence-key hashes: keep it private.\n"
        . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
    $rows = 0;
    foreach ($tables as $table) {
        $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
        $ddl = preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', (string)$create[1]);
        $ddl = preg_replace('/ AUTO_INCREMENT=\d+/', '', (string)$ddl);
        $emit("-- $table\n" . $ddl . ";\n");
        if (in_array($table, DBEXPORT_SKIP_ROWS, true)) {
            $emit("-- $table: rows not exported (they rebuild themselves)\n\n");
            continue;
        }
        $n = 0;
        // Unbuffered cursor: rows stream from the server one at a time instead of the whole table landing in memory.
        // No other query may run on this connection until the cursor is closed (quote() is client-side, so it is
        // fine), and the connection's own setting is put back afterwards, even when $emit throws.
        $unbuffered = defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY') && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $was = $unbuffered ? $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) : null;
        $st = null;
        try {
            if ($unbuffered) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            }
            $st = $pdo->query('SELECT * FROM `' . $table . '`');
            while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
                $cols = implode(', ', array_map(static fn($c) => '`' . str_replace('`', '``', (string)$c) . '`', array_keys($row)));
                $vals = implode(', ', array_map(static fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row)));
                $emit("INSERT INTO `$table` ($cols) VALUES ($vals);\n");
                $n++;
            }
        } finally {
            if ($st instanceof PDOStatement) {
                $st->closeCursor();
            }
            $st = null;
            if ($unbuffered) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $was);
            }
        }
        $emit("-- $table: $n row(s)\n\n");
        $rows += $n;
    }
    $emit("SET FOREIGN_KEY_CHECKS = 1;\n-- complete: every table exported\n");
    return ['tables' => count($tables), 'rows' => $rows];
}
