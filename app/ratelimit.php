<?php
// Fixed-window counters in the rate_limits table. Returns true while under the limit.

function rate_limit_hit(PDO $pdo, string $bucket, int $limit, int $windowSeconds): bool
{
    $now = time();
    $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
    $bucket = substr($bucket, 0, 100);

    $ins = $pdo->prepare(
        'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE hits = hits + 1'
    );
    $ins->execute([$bucket, $windowStart]);

    $sel = $pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?');
    $sel->execute([$bucket, $windowStart]);
    $hits = (int)$sel->fetchColumn();

    if (mt_rand(1, 50) === 1) {
        $del = $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?');
        $del->execute([$now - 172800]);
    }
    return $hits <= $limit;
}
