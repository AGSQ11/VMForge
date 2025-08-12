<?php
namespace VMForge\Models;

use VMForge\Core\DB;
use PDO;

class User {
    /**
     * Find user by ID
     */
    public static function findById(int $id): ?array {
        return DB::fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
    }
    
    /**
     * Find user by email
     */
    public static function findByEmail(string $email): ?array {
        return DB::fetchOne('SELECT * FROM users WHERE email = ?', [$email]);
    }
    
    /**
     * Create new user
     */
    public static function create(array $data): int {
        $stmt = DB::execute('
            INSERT INTO users (email, password_hash, created_at)
            VALUES (?, ?, NOW())
        ', [
            $data['email'],
            password_hash($data['password'], PASSWORD_ARGON2ID)
        ]);
        
        $userId = DB::lastInsertId();
        
        // Assign default customer role
        self::assignRole($userId, 'customer');
        
        return $userId;
    }
    
    /**
     * Update user password
     */
    public static function updatePassword(int $userId, string $password): bool {
        $stmt = DB::execute('
            UPDATE users 
            SET password_hash = ?
            WHERE id = ?
        ', [
            password_hash($password, PASSWORD_ARGON2ID),
            $userId
        ]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Get user permissions
     */
    public static function getPermissions(int $userId): array {
        $permissions = DB::fetchAll('
            SELECT DISTINCT p.name
            FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            JOIN user_roles ur ON rp.role_id = ur.role_id
            WHERE ur.user_id = ?
        ', [$userId]);
        
        return array_column($permissions, 'name');
    }
    
    /**
     * Check if user has role
     */
    public static function hasRole(int $userId, string $roleName): bool {
        $result = DB::fetchOne('
            SELECT COUNT(*) as count
            FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ? AND r.name = ?
        ', [$userId, $roleName]);
        
        return $result && $result['count'] > 0;
    }
    
    /**
     * Assign role to user
     */
    public static function assignRole(int $userId, string $roleName): bool {
        $role = DB::fetchOne('SELECT id FROM roles WHERE name = ?', [$roleName]);
        
        if (!$role) {
            return false;
        }
        
        try {
            DB::execute('
                INSERT INTO user_roles (user_id, role_id)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE role_id = role_id
            ', [$userId, $role['id']]);
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Remove role from user
     */
    public static function removeRole(int $userId, string $roleName): bool {
        $role = DB::fetchOne('SELECT id FROM roles WHERE name = ?', [$roleName]);
        
        if (!$role) {
            return false;
        }
        
        $stmt = DB::execute('
            DELETE FROM user_roles
            WHERE user_id = ? AND role_id = ?
        ', [$userId, $role['id']]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Get all users
     */
    public static function all(int $limit = 100, int $offset = 0): array {
        return DB::fetchAll('
            SELECT id, email, created_at, last_login_at, failed_logins, locked_until
            FROM users
            ORDER BY id DESC
            LIMIT ? OFFSET ?
        ', [$limit, $offset]);
    }
    
    /**
     * Update user profile
     */
    public static function update(int $userId, array $data): bool {
        $fields = [];
        $values = [];
        
        foreach (['email', 'totp_secret'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $values[] = $userId;
        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
        
        $stmt = DB::execute($sql, $values);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Delete user
     */
    public static function delete(int $userId): bool {
        return DB::transaction(function ($pdo) use ($userId) {
            // Delete related records
            $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM user_projects WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$userId]);
            
            // Delete user
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            
            return $stmt->rowCount() > 0;
        });
    }
}
