<?php
namespace VMForge\Models;
use VMForge\Core\DB;
use PDO;
class User {
    public static function findByEmail(string $email): ?array {
        $st = DB::pdo()->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
        $st->execute([$email]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
    public static function findById(int $id): ?array {
        $st = DB::pdo()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}
