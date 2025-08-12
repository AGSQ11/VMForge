<?php
namespace VMForge\Models;

use VMForge\Core\DB;
use VMForge\Core\UUID;
use PDO;

class VM {
    /**
     * Get all VMs with optional filtering
     */
    public static function all(array $filters = []): array {
        $where = [];
        $params = [];
        
        if (isset($filters['project_id'])) {
            $where[] = 'vi.project_id = ?';
            $params[] = $filters['project_id'];
        }
        
        if (isset($filters['node_id'])) {
            $where[] = 'vi.node_id = ?';
            $params[] = $filters['node_id'];
        }
        
        if (isset($filters['type'])) {
            $where[] = 'vi.type = ?';
            $params[] = $filters['type'];
        }
        
        if (isset($filters['status'])) {
            $where[] = 'vi.status = ?';
            $params[] = $filters['status'];
        }
        
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        
        return DB::fetchAll("
            SELECT vi.*, n.name as node_name, p.name as project_name
            FROM vm_instances vi
            LEFT JOIN nodes n ON vi.node_id = n.id
            LEFT JOIN projects p ON vi.project_id = p.id
            $whereClause
            ORDER BY vi.id DESC
        ", $params);
    }
    
    /**
     * Find VM by UUID
     */
    public static function findByUuid(string $uuid): ?array {
        return DB::fetchOne('
            SELECT vi.*, n.name as node_name, p.name as project_name
            FROM vm_instances vi
            LEFT JOIN nodes n ON vi.node_id = n.id
            LEFT JOIN projects p ON vi.project_id = p.id
            WHERE vi.uuid = ?
        ', [$uuid]);
    }
    
    /**
     * Find VM by name
     */
    public static function findByName(string $name): ?array {
        return DB::fetchOne('
            SELECT vi.*, n.name as node_name
            FROM vm_instances vi
            LEFT JOIN nodes n ON vi.node_id = n.id
            WHERE vi.name = ?
        ', [$name]);
    }
    
    /**
     * Create new VM
     */
    public static function create(array $data): int {
        $uuid = $data['uuid'] ?? UUID::v4();
        
        DB::execute('
            INSERT INTO vm_instances (
                uuid, project_id, node_id, name, type, vcpus, memory_mb, 
                disk_gb, image_id, bridge, ip_address, mac_address, 
                subnet_id, subnet6_id, storage_type, storage_pool_id, 
                vlan_tag, status, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )
        ', [
            $uuid,
            $data['project_id'] ?? null,
            $data['node_id'],
            $data['name'],
            $data['type'] ?? 'kvm',
            $data['vcpus'],
            $data['memory_mb'],
            $data['disk_gb'],
            $data['image_id'] ?? null,
            $data['bridge'] ?? 'br0',
            $data['ip_address'] ?? null,
            $data['mac_address'] ?? null,
            $data['subnet_id'] ?? null,
            $data['subnet6_id'] ?? null,
            $data['storage_type'] ?? 'qcow2',
            $data['storage_pool_id'] ?? null,
            $data['vlan_tag'] ?? null,
            $data['status'] ?? 'creating'
        ]);
        
        return DB::lastInsertId();
    }
    
    /**
     * Update VM
     */
    public static function update(string $uuid, array $data): bool {
        $fields = [];
        $values = [];
        
        $allowedFields = [
            'name', 'vcpus', 'memory_mb', 'disk_gb', 'ip_address',
            'status', 'power_state', 'firewall_mode'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $values[] = $uuid;
        $sql = 'UPDATE vm_instances SET ' . implode(', ', $fields) . ' WHERE uuid = ?';
        
        $stmt = DB::execute($sql, $values);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Delete VM
     */
    public static function delete(string $uuid): bool {
        return DB::transaction(function ($pdo) use ($uuid) {
            // Delete related records
            $pdo->prepare('DELETE FROM ip_allocations WHERE vm_uuid = ?')->execute([$uuid]);
            $pdo->prepare('DELETE FROM bandwidth_usage WHERE vm_uuid = ?')->execute([$uuid]);
            $pdo->prepare('DELETE FROM bandwidth_counters WHERE vm_uuid = ?')->execute([$uuid]);
            $pdo->prepare('DELETE FROM firewall_rules WHERE vm_uuid = ?')->execute([$uuid]);
            $pdo->prepare('DELETE FROM backups WHERE vm_uuid = ?')->execute([$uuid]);
            $pdo->prepare('DELETE FROM snapshots WHERE vm_uuid = ?')->execute([$uuid]);
            
            // Delete VM
            $stmt = $pdo->prepare('DELETE FROM vm_instances WHERE uuid = ?');
            $stmt->execute([$uuid]);
            
            return $stmt->rowCount() > 0;
        });
    }
    
    /**
     * Get VM metrics
     */
    public static function getMetrics(string $uuid): array {
        $metrics = DB::fetchOne('
            SELECT 
                cpu_usage_percent,
                memory_used_mb,
                disk_read_bytes,
                disk_write_bytes,
                network_rx_bytes,
                network_tx_bytes,
                updated_at
            FROM vm_metrics
            WHERE vm_uuid = ?
            ORDER BY updated_at DESC
            LIMIT 1
        ', [$uuid]);
        
        return $metrics ?: [
            'cpu_usage_percent' => 0,
            'memory_used_mb' => 0,
            'disk_read_bytes' => 0,
            'disk_write_bytes' => 0,
            'network_rx_bytes' => 0,
            'network_tx_bytes' => 0,
            'updated_at' => null
        ];
    }
    
    /**
     * Get VM bandwidth usage
     */
    public static function getBandwidthUsage(string $uuid, string $period = 'day'): array {
        $interval = match($period) {
            'hour' => '1 HOUR',
            'day' => '1 DAY',
            'week' => '7 DAY',
            'month' => '30 DAY',
            default => '1 DAY'
        };
        
        return DB::fetchAll('
            SELECT 
                interface,
                SUM(rx_bytes) as rx_total,
                SUM(tx_bytes) as tx_total,
                MIN(period_start) as period_start,
                MAX(period_end) as period_end
            FROM bandwidth_usage
            WHERE vm_uuid = ? 
                AND period_start > DATE_SUB(NOW(), INTERVAL ' . $interval . ')
            GROUP BY interface
        ', [$uuid]);
    }
    
    /**
     * Check if VM name is unique on node
     */
    public static function isNameUnique(string $name, int $nodeId, ?string $excludeUuid = null): bool {
        $sql = 'SELECT COUNT(*) FROM vm_instances WHERE name = ? AND node_id = ?';
        $params = [$name, $nodeId];
        
        if ($excludeUuid) {
            $sql .= ' AND uuid != ?';
            $params[] = $excludeUuid;
        }
        
        $stmt = DB::execute($sql, $params);
        return $stmt->fetchColumn() == 0;
    }
}
