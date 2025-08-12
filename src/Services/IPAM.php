<?php
namespace VMForge\Services;

use VMForge\Core\DB;

class IPAM {
    /**
     * Get next available IP from pool
     */
    public static function nextFree(int $poolId): ?string {
        $pool = DB::fetchOne('SELECT * FROM ip_pools WHERE id = ?', [$poolId]);
        
        if (!$pool) {
            return null;
        }
        
        return self::findNextAvailable($pool);
    }
    
    /**
     * Get next available IP from subnet
     */
    public static function nextFreeSubnetIp(int $subnetId): ?string {
        $subnet = DB::fetchOne('SELECT * FROM subnets WHERE id = ?', [$subnetId]);
        
        if (!$subnet) {
            return null;
        }
        
        return self::findNextInSubnet($subnet['cidr'], $subnet['id']);
    }
    
    /**
     * Find next available IP in CIDR range
     */
    private static function findNextAvailable(array $pool): ?string {
        [$network, $bits] = explode('/', $pool['cidr']);
        $version = $pool['version'];
        
        if ($version == 4) {
            return self::findNextIPv4($network, (int)$bits, $pool['id']);
        } else {
            return self::findNextIPv6($network, (int)$bits, $pool['id']);
        }
    }
    
    /**
     * Find next available IPv4 address
     */
    private static function findNextIPv4(string $network, int $bits, int $poolId): ?string {
        $networkLong = ip2long($network);
        $maskLong = -1 << (32 - $bits);
        $networkStart = $networkLong & $maskLong;
        $networkEnd = $networkStart | ~$maskLong;
        
        // Get allocated IPs
        $allocated = DB::fetchAll('
            SELECT ip_address FROM ip_allocations 
            WHERE pool_id = ? AND allocated = 1
        ', [$poolId]);
        
        $allocatedLongs = array_map(function($row) {
            return ip2long($row['ip_address']);
        }, $allocated);
        
        // Start from .2 (reserve .1 for gateway)
        for ($i = $networkStart + 2; $i < $networkEnd; $i++) {
            if (!in_array($i, $allocatedLongs)) {
                $ip = long2ip($i);
                
                // Reserve this IP
                DB::execute('
                    INSERT INTO ip_allocations (pool_id, ip_address, allocated)
                    VALUES (?, ?, 1)
                    ON DUPLICATE KEY UPDATE allocated = 1
                ', [$poolId, $ip]);
                
                return $ip;
            }
        }
        
        return null;
    }
    
    /**
     * Find next available IPv6 address
     */
    private static function findNextIPv6(string $network, int $bits, int $poolId): ?string {
        // For simplicity, generate a random address in the subnet
        // In production, you'd want proper IPv6 address management
        
        $parts = explode(':', $network);
        $hostPart = dechex(rand(1, 65535));
        $parts[count($parts) - 1] = $hostPart;
        
        $ip = implode(':', $parts);
        
        // Check if already allocated
        $exists = DB::fetchOne('
            SELECT id FROM ip_allocations 
            WHERE pool_id = ? AND ip_address = ?
        ', [$poolId, $ip]);
        
        if (!$exists) {
            DB::execute('
                INSERT INTO ip_allocations (pool_id, ip_address, allocated)
                VALUES (?, ?, 1)
            ', [$poolId, $ip]);
            
            return $ip;
        }
        
        // Try again (in production, implement proper iteration)
        return self::findNextIPv6($network, $bits, $poolId);
    }
    
    /**
     * Find next IP in subnet with gateway reservation
     */
    private static function findNextInSubnet(string $cidr, int $subnetId): ?string {
        [$network, $bits] = explode('/', $cidr);
        $networkLong = ip2long($network);
        $maskLong = -1 << (32 - $bits);
        $networkStart = $networkLong & $maskLong;
        $networkEnd = $networkStart | ~$maskLong;
        
        // Get already assigned IPs in this subnet
        $assigned = DB::fetchAll('
            SELECT ip_address FROM vm_instances
            WHERE subnet_id = ? AND ip_address IS NOT NULL
        ', [$subnetId]);
        
        $assignedLongs = array_map(function($row) {
            return ip2long($row['ip_address']);
        }, $assigned);
        
        // Get gateway IP if set
        $subnet = DB::fetchOne('SELECT gateway_ip FROM subnets WHERE id = ?', [$subnetId]);
        $gatewayLong = $subnet['gateway_ip'] ? ip2long($subnet['gateway_ip']) : $networkStart + 1;
        $assignedLongs[] = $gatewayLong;
        
        // Find next available
        for ($i = $networkStart + 2; $i < $networkEnd; $i++) {
            if (!in_array($i, $assignedLongs)) {
                return long2ip($i);
            }
        }
        
        return null;
    }
    
    /**
     * Release IP address
     */
    public static function release(string $ip, int $poolId): bool {
        $stmt = DB::execute('
            UPDATE ip_allocations 
            SET allocated = 0, vm_uuid = NULL
            WHERE pool_id = ? AND ip_address = ?
        ', [$poolId, $ip]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Reserve IP address
     */
    public static function reserve(string $ip, int $poolId, ?string $vmUuid = null): bool {
        try {
            DB::execute('
                INSERT INTO ip_allocations (pool_id, ip_address, vm_uuid, allocated)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE allocated = 1, vm_uuid = ?
            ', [$poolId, $ip, $vmUuid, $vmUuid]);
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Validate IP is in pool/subnet
     */
    public static function validateInRange(string $ip, string $cidr): bool {
        [$network, $bits] = explode('/', $cidr);
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            $networkLong = ip2long($network);
            $maskLong = -1 << (32 - (int)$bits);
            
            return ($ipLong & $maskLong) === ($networkLong & $maskLong);
        }
        
        // IPv6 validation would go here
        return false;
    }
}
