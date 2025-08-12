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

    public static function getRoles(int $userId): array
    {
        $st = DB::pdo()->prepare('SELECT r.* FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?');
        $st->execute([$userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function assignRole(int $userId, int $roleId): void
    {
        $st = DB::pdo()->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)');
        $st->execute([$userId, $roleId]);
    }

    public static function hasRole(int $userId, string $roleName): bool
    {
        $st = DB::pdo()->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = ? AND r.name = ?');
        $st->execute([$userId, $roleName]);
        return (bool)$st->fetchColumn();
    }

    public static function getPermissions(int $userId): array
    {
        $sql = '
            SELECT DISTINCT p.name
            FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            JOIN user_roles ur ON rp.role_id = ur.role_id
            WHERE ur.user_id = ?
        ';
        $st = DB::pdo()->prepare($sql);
        $st->execute([$userId]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function hasPermission(int $userId, string $permissionName): bool
    {
        // Always grant all permissions to the 'admin' role
        if (self::hasRole($userId, 'admin')) {
            return true;
        }

        $st = DB::pdo()->prepare('
            SELECT 1
            FROM user_roles ur
            JOIN role_permissions rp ON ur.role_id = rp.role_id
            JOIN permissions p ON rp.permission_id = p.id
            WHERE ur.user_id = ? AND p.name = ?
        ');
        $st->execute([$userId, $permissionName]);
        return (bool)$st->fetchColumn();
    }
}
