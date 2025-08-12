<?php
namespace VMForge\Models;

use VMForge\Core\DB;
use PDO;

class Transaction
{
    public static function create(int $invoiceId, string $gateway, string $transactionId, float $amount, string $status): int
    {
        $st = DB::pdo()->prepare(
            'INSERT INTO transactions (invoice_id, gateway, transaction_id, amount, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$invoiceId, $gateway, $transactionId, $amount, $status]);
        return (int)DB::pdo()->lastInsertId();
    }
}
