<?php
// PDO connection (prepared statements everywhere; emulation off).

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . env('DB_HOST', 'localhost')
            . ';port=' . (int)env('DB_PORT', 3306)
            . ';dbname=' . env('DB_NAME', '')
            . ';charset=utf8mb4';
        $pdo = new PDO($dsn, (string)env('DB_USER', ''), (string)env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
