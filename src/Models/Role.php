<?php
namespace VMForge\Models;

use VMForge\Core\DB;
use PDO;

class Role
{
    public static function findAll(): array
    {
        return DB::pdo()->query('SELECT * FROM roles ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function findByName(string $name): ?array
    {
        $st = DB::pdo()->prepare('SELECT * FROM roles WHERE name = ?');
        $st->execute([$name]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public static function getPermissions(int $roleId): array
    {
        $st = DB::pdo()->prepare('SELECT p.id, p.name FROM permissions p JOIN role_permissions rp ON p.id = rp.permission_id WHERE rp.role_id = ?');
        $st->execute([$roleId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function updatePermissions(int $roleId, array $permissionIds): void
    {
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            // Delete old permissions
            $st = $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?');
            $st->execute([$roleId]);

            // Add new permissions
            if (!empty($permissionIds)) {
                $st = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
                foreach ($permissionIds as $permId) {
                    $st->execute([$roleId, (int)$permId]);
                }
            }
            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
