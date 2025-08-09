<?php
namespace VMForge\Models;
use VMForge\Core\DB;
use PDO;

class Image {
    public static function all(): array {
        $st = DB::pdo()->query('SELECT * FROM images ORDER BY id DESC');
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function allByType(string $type): array {
        $st = DB::pdo()->prepare('SELECT * FROM images WHERE type=? ORDER BY id DESC');
        $st->execute([$type]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function create(array $d): int {
        $st = DB::pdo()->prepare('INSERT INTO images (name, type, source_url, sha256) VALUES (?,?,?,?)');
        $st->execute([$d['name'],$d['type'],$d['source_url'],$d['sha256']]);
        return (int)DB::pdo()->lastInsertId();
    }
}
