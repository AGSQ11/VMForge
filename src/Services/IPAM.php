<?php
namespace VMForge\Services;
use VMForge\Core\DB;
use PDO;

class IPAM {
    public static function nextFree(int $poolId): ?string {
        // simplistic allocator: iterate pool range and return first unallocated
        $pdo = DB::pdo();
        $st = $pdo->prepare('SELECT cidr FROM ip_pools WHERE id=?');
        $st->execute([$poolId]);
        $pool = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pool) return null;
        $cidr = $pool['cidr'];
        [$net, $prefix] = explode('/', $cidr, 2);
        if (strpos($net, ':') !== false) return null; // ipv6 skipped in MVP
        $parts = array_map('intval', explode('.', $net)); # v4 only MVP
        $size = 2 ** (32 - (int)$prefix);
        $start = ($parts[0]<<24) + ($parts[1]<<16) + ($parts[2]<<8) + $parts[3]
        ;
        # skip .0 and .1 commonly; start from +10
        $pdo->beginTransaction();
        $taken = $pdo->prepare('SELECT ip_address FROM ip_allocations WHERE pool_id=? AND allocated=1');
        $taken->execute([$poolId]);
        $used = array_flip(array_column($taken->fetchAll(PDO::FETCH_ASSOC), 'ip_address'));
        for ($i=10; $i<$size-1; $i++) {
            $ipLong = $start + $i;
            $ip = sprintf('%d.%d.%d.%d', ($ipLong>>24)&255, ($ipLong>>16)&255, ($ipLong>>8)&255, $ipLong&255);
            if (!isset($used[$ip])) {
                $ins = $pdo->prepare('INSERT INTO ip_allocations(pool_id, ip_address, allocated) VALUES(?,?,1)');
                $ins->execute([$poolId, $ip]);
                $pdo->commit();
                return $ip;
            }
        }
        $pdo->rollBack();
        return null;
    }
}
