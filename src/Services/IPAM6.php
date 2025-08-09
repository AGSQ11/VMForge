<?php
namespace VMForge\Services;

class IPAM6 {
    /**
     * Very simple validator for IPv6 /64 prefixes like 2001:db8:1234::/64
     * Returns [base, prefixlen] or [null, null] if invalid or not /64.
     */
    public static function parsePrefix64(string $cidr): array {
        $cidr = trim($cidr);
        if (!preg_match('~^([0-9a-fA-F:]+)/([0-9]{1,3})$~', $cidr, $m)) return [null, null];
        $base = $m[1]; $plen = (int)$m[2];
        if ($plen !== 64) return [null, null];
        // normalize base to compressed form with trailing :: (no host bits check here)
        if (substr($base, -2) !== '::') {
            if (substr($base, -1) !== ':') $base .= ':';
            $base .= ':';
        }
        return [$base, $plen];
    }

    public static function gatewayFromPrefix64(string $cidr): ?string {
        [$base, $plen] = self::parsePrefix64($cidr);
        if (!$base) return null;
        // router addr = base + 1 i.e., ::1
        if (substr($base, -2) !== '::') return null;
        return $base . '1';
    }
}
