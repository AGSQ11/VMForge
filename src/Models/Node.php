<?php
namespace VMForge\Models;
use VMForge\Core\DB;
use PDO;
class Node {
    public static function all(): array {
        $st = DB::pdo()->query('SELECT * FROM nodes ORDER BY id DESC');
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function create(array $data): int {
        $st = DB::pdo()->prepare('INSERT INTO nodes (name, mgmt_url, bridge, token) VALUES (?,?,?,?)');
        $st->execute([$data['name'],$data['mgmt_url'],$data['bridge'],$data['token']]);
        return (int)DB::pdo()->lastInsertId();
    }
    public static function byToken(string $token): ?array {
        $st = DB::pdo()->prepare('SELECT * FROM nodes WHERE token=? LIMIT 1');
        $st->execute([$token]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}
