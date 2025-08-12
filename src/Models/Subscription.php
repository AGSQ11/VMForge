<?php
namespace VMForge\Models;

use VMForge\Core\DB;
use PDO;

class Subscription
{
    public static function findByCustomerId(int $customerId): array
    {
        $st = DB::pdo()->prepare('SELECT * FROM subscriptions WHERE customer_id = ?');
        $st->execute([$customerId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create(int $customerId, int $productId, string $nextDueDate): int
    {
        $st = DB::pdo()->prepare(
            'INSERT INTO subscriptions (customer_id, product_id, next_due_date, status)
             VALUES (?, ?, ?, "pending")'
        );
        $st->execute([$customerId, $productId, $nextDueDate]);
        return (int)DB::pdo()->lastInsertId();
    }
}
