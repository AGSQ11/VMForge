<?php
namespace VMForge\Core;
use PDO;

class RateLimiter {
    /**
     * Throttle by key within a 60s window. On breach: 429 and exit.
     */
    public static function throttle(string $key, int $maxPerMinute = 120): void {
        $pdo = DB::pdo();
        // Use current minute bucket
        $bucketStart = date('Y-m-d H:i:00'); // align to minute
        // Try insert; if exists, update count
        $st = $pdo->prepare('INSERT INTO rate_limits(rl_key, bucket_start, count) VALUES (?,?,1)
                             ON DUPLICATE KEY UPDATE count = count + 1, id=LAST_INSERT_ID(id)');
        $st->execute([$key, $bucketStart]);
        $id = (int)$pdo->lastInsertId();
        // Read back count
        $st2 = $pdo->prepare('SELECT count FROM rate_limits WHERE id=?');
        $st2->execute([$id]);
        $count = (int)$st2->fetchColumn();
        if ($count > $maxPerMinute) {
            http_response_code(429);
            header('Retry-After: 60');
            echo 'rate limit exceeded';
            exit;
        }
    }
}
