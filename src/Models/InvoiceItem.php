<?php
namespace VMForge\Models;

use VMForge\Core\DB;
use PDO;

class InvoiceItem
{
    public static function create(int $invoiceId, string $description, float $amount): int
    {
        $st = DB::pdo()->prepare(
            'INSERT INTO invoice_items (invoice_id, description, amount)
             VALUES (?, ?, ?)'
        );
        $st->execute([$invoiceId, $description, $amount]);
        return (int)DB::pdo()->lastInsertId();
    }
}
