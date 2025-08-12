<?php
namespace VMForge\Models;

use VMForge\Core\DB;
use PDO;

class Customer
{
    public static function findByUserId(int $userId): ?array
    {
        $st = DB::pdo()->prepare('SELECT * FROM customers WHERE user_id = ?');
        $st->execute([$userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public static function create(int $userId, array $data): int
    {
        $st = DB::pdo()->prepare(
            'INSERT INTO customers (user_id, company_name, address1, address2, city, state, zip, country)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $userId,
            $data['company_name'] ?? null,
            $data['address1'] ?? null,
            $data['address2'] ?? null,
            $data['city'] ?? null,
            $data['state'] ?? null,
            $data['zip'] ?? null,
            $data['country'] ?? null,
        ]);
        return (int)DB::pdo()->lastInsertId();
    }
}
