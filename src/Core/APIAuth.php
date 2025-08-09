<?php
namespace VMForge\Core;
use VMForge\Core\DB;
use PDO;

class APIAuth {
    public static function userFromBearer(): ?array {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($hdr, 'Bearer ')) return null;
        $token = substr($hdr, 7);
        $hash = hash('sha256', $token);
        $st = DB::pdo()->prepare('SELECT t.*, u.email FROM api_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? LIMIT 1');
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        // update last_used_at lazily
        DB::pdo()->prepare('UPDATE api_tokens SET last_used_at=NOW() WHERE id=?')->execute([$row['id']]);
        return $row;
    }
}
