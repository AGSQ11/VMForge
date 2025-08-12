<?php
namespace VMForge\Models;

use VMForge\Core\DB;
use PDO;

class Permission
{
    public static function findAll(): array
    {
        return DB::pdo()->query('SELECT * FROM permissions ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
    }
}
