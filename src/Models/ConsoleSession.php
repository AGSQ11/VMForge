<?php
namespace VMForge\Models;
use VMForge\Core\DB;
use PDO;

class ConsoleSession {
    public static function create(array $d): int {
        $st = DB::pdo()->prepare('INSERT INTO console_sessions(vm_uuid,node_id,token,listen_port,expires_at) VALUES (?,?,?,?,?)');
        $st->execute([$d['vm_uuid'],$d['node_id'],$d['token'],$d['listen_port'],$d['expires_at']]);
        return (int)DB::pdo()->lastInsertId();
    }
    public static function findByToken(string $token): ?array {
        $st = DB::pdo()->prepare('SELECT * FROM console_sessions WHERE token=? LIMIT 1');
        $st->execute([$token]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
    public static function purgeExpired(): void {
        DB::pdo()->query('DELETE FROM console_sessions WHERE expires_at < NOW()');
    }
}
