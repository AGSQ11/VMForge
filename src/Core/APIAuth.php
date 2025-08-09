<?php
namespace VMForge\Core;
use PDO;

class APIAuth {
    public static function assertProjectScope(?int $projectId): void {
        $u = self::userFromBearer();
        if (!$u) { http_response_code(401); echo json_encode(['error'=>'auth']); exit; }
        if ($u['scope'] === 'admin') return;
        if (!$projectId || (int)$u['project_id'] !== (int)$projectId) {
            http_response_code(403); echo json_encode(['error'=>'forbidden']); exit;
        }
    }
    public static function userFromBearer(): ?array {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('~^Bearer\s+(.+)$~i', $hdr, $m)) return null;
        $token = trim($m[1]);
        if ($token === '') return null;
        $hash = Security::hashToken($token);
        $pdo = DB::pdo();
        if (!self::checkRateLimit($hash, 120)) {
            http_response_code(429); echo json_encode(['error'=>'rate_limited']); exit;
        }
        $st = $pdo->prepare('SELECT t.user_id, t.project_id, t.scope, u.email FROM api_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? LIMIT 1');
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $pdo->prepare('UPDATE api_tokens SET last_used_at=NOW() WHERE token_hash=?')->execute([$hash]);
        return $row;
    }
    private static function checkRateLimit(string $hash, int $limit): bool {
        $pdo = DB::pdo();
        $windowStart = date('Y-m-d H:i:00');
        $st = $pdo->prepare('SELECT count FROM api_rate_limits WHERE token_hash=? AND window_start=?');
        $st->execute([$hash, $windowStart]);
        $count = (int)($st->fetchColumn() ?: 0);
        if ($count >= $limit) return false;
        if ($count === 0) {
            $pdo->prepare('INSERT INTO api_rate_limits(token_hash, window_start, count) VALUES (?,?,1)')->execute([$hash, $windowStart]);
        } else {
            $pdo->prepare('UPDATE api_rate_limits SET count=count+1 WHERE token_hash=? AND window_start=?')->execute([$hash, $windowStart]);
        }
        return true;
    }
}
