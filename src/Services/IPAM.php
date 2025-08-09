<?php
namespace VMForge\Services;

use VMForge\Core\DB;
use PDO;

class IPAM {
    public static function nextFreeSubnetIp(int $subnetId): ?string {
        $pdo = DB::pdo();
        $st = $pdo->prepare('SELECT cidr, gateway_ip FROM subnets WHERE id=?');
        $st->execute([$subnetId]);
        $sn = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sn) return null;
        $cidr = $sn['cidr'];
        if (!preg_match('~^(\d+)\.(\d+)\.(\d+)\.(\d+)/(\d+)$~', $cidr, $m)) return null;
        $base = $m[1].'.'.$m[2].'.'.$m[3].'.'.$m[4];
        $prefix = (int)$m[5];
        $netLong = (ip2long($base) & (-1 << (32 - $prefix)));
        $bcast = $netLong | ((1 << (32 - $prefix)) - 1);
        $used = [];
        // existing allocations in this subnet
        $rows = $pdo->prepare('SELECT ip_address FROM vm_instances WHERE subnet_id=?');
        $rows->execute([$subnetId]);
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ip = $r['ip_address']; if ($ip) $used[$ip] = true;
        }
        // reserve gateway and network/broadcast
        if (!empty($sn['gateway_ip'])) $used[$sn['gateway_ip']] = true;
        $used[long2ip($netLong)] = true;
        $used[long2ip($bcast)] = true;
        for ($i = $netLong + 1; $i < $bcast; $i++) {
            $cand = long2ip($i);
            if (empty($used[$cand])) return $cand;
        }
        return null;
    }
}
