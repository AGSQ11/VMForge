<?php
namespace VMForge\Models;

use VMForge\Core\DB;
use PDO;

class Product
{
    public static function findAll(): array
    {
        $st = DB::pdo()->query('SELECT * FROM products WHERE is_active = TRUE ORDER BY name ASC');
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function findById(int $id): ?array
    {
        $st = DB::pdo()->prepare('SELECT * FROM products WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}
