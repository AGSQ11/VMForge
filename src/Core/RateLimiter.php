<?php
namespace VMForge\Core;

use VMForge\Core\Env;
use VMForge\Core\DB;

class RateLimiter {
    /**
     * Throttle a key to $max events per $windowSec seconds.
     * Throws 429 if exceeded.
     */
    public static function throttle(string $key, int $maxPerWindow = 60, int $windowSec = 60): void {
        // Prefer Redis if available
        $redisHost = Env::get('REDIS_HOST', '');
        if ($redisHost !== '') {
            $r = new \Redis();
            $r->connect($redisHost, (int)Env::get('REDIS_PORT','6379'));
            $prefix = 'vmforge:rl:';
            $k = $prefix . $key;
            $cnt = $r->incr($k);
            if ($cnt === 1) $r->expire($k, $windowSec);
            if ($cnt > $maxPerWindow) {
                http_response_code(429);
                header('Retry-After: ' . $windowSec);
                echo 'rate limit exceeded';
                exit;
            }
            return;
        }

        // Fallback: SQL counter with window
        $pdo = DB::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (k VARCHAR(190) PRIMARY KEY, cnt INT NOT NULL, window_start TIMESTAMP NOT NULL)');
        $now = date('Y-m-d H:i:s');
        $st = $pdo->prepare('SELECT cnt, window_start FROM rate_limits WHERE k=?');
        $st->execute([$key]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $start = strtotime($row['window_start']);
            if (time() - $start >= $windowSec) {
                $upd = $pdo->prepare('UPDATE rate_limits SET cnt=1, window_start=? WHERE k=?');
                $upd->execute([$now, $key]);
                return;
            }
            $cnt = (int)$row['cnt'] + 1;
            if ($cnt > $maxPerWindow) {
                http_response_code(429);
                header('Retry-After: ' . ($windowSec - (time()-$start)));
                echo 'rate limit exceeded';
                exit;
            }
            $upd = $pdo->prepare('UPDATE rate_limits SET cnt=? WHERE k=?');
            $upd->execute([$cnt, $key]);
        } else {
            $ins = $pdo->prepare('INSERT INTO rate_limits (k, cnt, window_start) VALUES (?, 1, ?)');
            $ins->execute([$key, $now]);
        }
    }
}
