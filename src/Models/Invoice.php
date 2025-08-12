<?php
namespace VMForge\Models;

use VMForge\Core\DB;
use PDO;

class Invoice
{
    public static function findByCustomerId(int $customerId): array
    {
        $st = DB::pdo()->prepare('SELECT * FROM invoices WHERE customer_id = ? ORDER BY issue_date DESC');
        $st->execute([$customerId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create(int $customerId, int $subscriptionId, string $issueDate, string $dueDate, float $total): int
    {
        $st = DB::pdo()->prepare(
            'INSERT INTO invoices (customer_id, subscription_id, issue_date, due_date, total, status)
             VALUES (?, ?, ?, ?, ?, "unpaid")'
        );
        $st->execute([$customerId, $subscriptionId, $issueDate, $dueDate, $total]);
        return (int)DB::pdo()->lastInsertId();
    }
}
