<?php
namespace VMForge\Services;
use VMForge\Core\DB;
use PDO;

class IPAM {
    private static function ipToLong(string $ip) { return sprintf('%u', ip2long($ip)); }
    private static function longToIp($long) { return long2ip((int)$long); }

    public static function nextFree(int $poolId): ?string {
        $pdo = DB::pdo();
        $st = $pdo->prepare('SELECT * FROM ip_pools WHERE id=?');
        $st->execute([$poolId]);
        $pool = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pool) return null;
        [$net, $prefix] = explode('/', $pool['cidr'], 2);
        $version = (int)$pool['version'];
        $pdo->beginTransaction();
        $taken = $pdo->prepare('SELECT ip_address FROM ip_allocations WHERE pool_id=? AND allocated=1');
        $taken->execute([$poolId]);
        $used = array_flip(array_column($taken->fetchAll(PDO::FETCH_ASSOC), 'ip_address'));

        if ($version === 4) {
            $start = self::ipToLong($net);
            $size = 2 ** (32 - (int)$prefix);
            for ($i=10; $i<$size-1; $i++) {
                $ipLong = $start + $i;
                $ip = self::longToIp($ipLong);
                if (!isset($used[$ip])) {
                    $ins = $pdo->prepare('INSERT INTO ip_allocations(pool_id, ip_address, allocated) VALUES(?,?,1)');
                    $ins->execute([$poolId, $ip]);
                    $pdo->commit();
                    return $ip;
                }
            }
            $pdo->rollBack();
            return null;
        } else {
            // IPv6: iterate last 16 bits inside the /64 (MVP)
            if ((int)$prefix > 64) { $pdo->rollBack(); return null; }
            // Use php gmp for big ints if available
            if (!function_exists('gmp_init')) { $pdo->rollBack(); return null; }
            $netBin = inet_pton($net);
            if ($netBin === false) { $pdo->rollBack(); return null; }
            $base = gmp_import($netBin);
            // start at +0x1000 to avoid router addresses
            for ($i=0x1000; $i<0xffff; $i++) {
                $candidate = gmp_add($base, $i);
                $packed = gmp_export($candidate);
                $packed = str_pad($packed, 16, "\0", STR_PAD_LEFT);
                $ip = inet_ntop($packed);
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
}
