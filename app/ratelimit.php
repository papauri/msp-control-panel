<?php
// Fixed-window counters in the rate_limits table. Returns true while under the limit.

function rate_limit_hit(PDO $pdo, string $bucket, int $limit, int $windowSeconds): bool
{
    $now = time();
    $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
    $bucket = substr($bucket, 0, 100);

    $ins = $pdo->prepare(
        'INSERT INTO cp_rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE hits = hits + 1'
    );
    $ins->execute([$bucket, $windowStart]);

    $sel = $pdo->prepare('SELECT hits FROM cp_rate_limits WHERE bucket = ? AND window_start = ?');
    $sel->execute([$bucket, $windowStart]);
    $hits = (int)$sel->fetchColumn();

    if (mt_rand(1, 50) === 1) {
        $del = $pdo->prepare('DELETE FROM cp_rate_limits WHERE window_start < ?');
        $del->execute([$now - 172800]);
    }
    return $hits <= $limit;
}

// Failure counters (2026-11-09): read without adding, add only on a failure, clear on success. Same table and window
// arithmetic as rate_limit_hit. Callers pass a hashed bucket name so long values are never cut and merged.

function rate_limit_count(PDO $pdo, string $bucket, int $windowSeconds): int
{
    $windowStart = intdiv(time(), $windowSeconds) * $windowSeconds;
    $sel = $pdo->prepare('SELECT hits FROM cp_rate_limits WHERE bucket = ? AND window_start = ?');
    $sel->execute([substr($bucket, 0, 100), $windowStart]);
    return (int)$sel->fetchColumn();
}

function rate_limit_add(PDO $pdo, string $bucket, int $windowSeconds): void
{
    $windowStart = intdiv(time(), $windowSeconds) * $windowSeconds;
    $pdo->prepare('INSERT INTO cp_rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1')
        ->execute([substr($bucket, 0, 100), $windowStart]);
}

function rate_limit_clear(PDO $pdo, string $bucket): void
{
    $pdo->prepare('DELETE FROM cp_rate_limits WHERE bucket = ?')->execute([substr($bucket, 0, 100)]);
}
