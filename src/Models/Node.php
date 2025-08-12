<?php
namespace VMForge\Models;

use VMForge\Core\DB;
use VMForge\Core\Security;
use PDO;

class Node {
    /**
     * Get all nodes
     */
    public static function all(): array {
        return DB::fetchAll('
            SELECT n.*, 
                   COUNT(DISTINCT vi.id) as vm_count,
                   COALESCE(SUM(vi.vcpus), 0) as total_vcpus,
                   COALESCE(SUM(vi.memory_mb), 0) as total_memory_mb
            FROM nodes n
            LEFT JOIN vm_instances vi ON n.id = vi.node_id
            GROUP BY n.id
            ORDER BY n.id ASC
        ');
    }
    
    /**
     * Find node by ID
     */
    public static function findById(int $id): ?array {
        return DB::fetchOne('SELECT * FROM nodes WHERE id = ?', [$id]);
    }
    
    /**
     * Find node by token
     */
    public static function findByToken(string $token): ?array {
        $hash = Security::hashToken($token);
        
        // Try new hash first
        $node = DB::fetchOne('SELECT * FROM nodes WHERE token_hash = ?', [$hash]);
        
        if ($node) {
            return $node;
        }
        
        // Try old hash (for rotation)
        $node = DB::fetchOne('SELECT * FROM nodes WHERE token_old_hash = ?', [$hash]);
        
        if ($node) {
            // Check if rotation period is still valid (24 hours)
            if ($node['token_rotated_at']) {
                $rotatedAt = strtotime($node['token_rotated_at']);
                if (time() - $rotatedAt > 86400) {
                    return null; // Rotation period expired
                }
            }
            return $node;
        }
        
        // Fallback to legacy plain token (will be removed in future)
        return DB::fetchOne('SELECT * FROM nodes WHERE token = ?', [$token]);
    }
    
    /**
     * Create new node
     */
    public static function create(array $data): int {
        $token = Security::generateToken(32);
        $tokenHash = Security::hashToken($token);
        
        DB::execute('
            INSERT INTO nodes (name, mgmt_url, bridge, token, token_hash, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ', [
            $data['name'],
            $data['mgmt_url'],
            $data['bridge'] ?? 'br0',
            '', // Don't store plain token
            $tokenHash
        ]);
        
        $nodeId = DB::lastInsertId();
        
        // Return the plain token for display (only time it's shown)
        return ['id' => $nodeId, 'token' => $token];
    }
    
    /**
     * Update node
     */
    public static function update(int $id, array $data): bool {
        $fields = [];
        $values = [];
        
        foreach (['name', 'mgmt_url', 'bridge'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $values[] = $id;
        $sql = 'UPDATE nodes SET ' . implode(', ', $fields) . ' WHERE id = ?';
        
        $stmt = DB::execute($sql, $values);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Rotate node token
     */
    public static function rotateToken(int $id): string {
        $newToken = Security::generateToken(32);
        $newHash = Security::hashToken($newToken);
        
        // Get current hash to move to old
        $node = self::findById($id);
        $oldHash = $node['token_hash'] ?? null;
        
        DB::execute('
            UPDATE nodes 
            SET token_hash = ?,
                token_old_hash = ?,
                token_rotated_at = NOW()
            WHERE id = ?
        ', [$newHash, $oldHash, $id]);
        
        return $newToken;
    }
    
    /**
     * Delete node
     */
    public static function delete(int $id): bool {
        // Check if node has VMs
        $vmCount = DB::fetchOne('SELECT COUNT(*) as count FROM vm_instances WHERE node_id = ?', [$id]);
        
        if ($vmCount && $vmCount['count'] > 0) {
            throw new \Exception('Cannot delete node with active VMs');
        }
        
        $stmt = DB::execute('DELETE FROM nodes WHERE id = ?', [$id]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Update node heartbeat
     */
    public static function updateHeartbeat(int $id): void {
        DB::execute('UPDATE nodes SET last_seen_at = NOW() WHERE id = ?', [$id]);
    }
    
    /**
     * Get node statistics
     */
    public static function getStats(int $id): array {
        $stats = DB::fetchOne('
            SELECT 
                COUNT(DISTINCT vi.id) as vm_count,
                COUNT(DISTINCT CASE WHEN vi.power_state = "running" THEN vi.id END) as running_vms,
                COALESCE(SUM(vi.vcpus), 0) as allocated_vcpus,
                COALESCE(SUM(vi.memory_mb), 0) as allocated_memory_mb,
                COALESCE(SUM(vi.disk_gb), 0) as allocated_disk_gb
            FROM vm_instances vi
            WHERE vi.node_id = ?
        ', [$id]);
        
        return $stats ?: [
            'vm_count' => 0,
            'running_vms' => 0,
            'allocated_vcpus' => 0,
            'allocated_memory_mb' => 0,
            'allocated_disk_gb' => 0
        ];
    }
    
    /**
     * Check node health
     */
    public static function checkHealth(int $id): array {
        $node = self::findById($id);
        
        if (!$node) {
            return ['healthy' => false, 'reason' => 'Node not found'];
        }
        
        // Check last heartbeat
        if ($node['last_seen_at']) {
            $lastSeen = strtotime($node['last_seen_at']);
            $minutesAgo = (time() - $lastSeen) / 60;
            
            if ($minutesAgo > 5) {
                return [
                    'healthy' => false,
                    'reason' => 'No heartbeat for ' . round($minutesAgo) . ' minutes'
                ];
            }
        }
        
        // Check if management URL is reachable
        $ch = curl_init($node['mgmt_url'] . '/healthz');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_NOBODY => true
        ]);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return [
                'healthy' => false,
                'reason' => 'Management URL not reachable'
            ];
        }
        
        return ['healthy' => true, 'reason' => 'OK'];
    }
}
