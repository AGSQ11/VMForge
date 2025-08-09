<?php
namespace VMForge\Services;

class PDNS {
    private static function api(string $method, string $path, $payload=null): array {
        $base = $_ENV['PDNS_API_URL'] ?? '';
        $key  = $_ENV['PDNS_API_KEY'] ?? '';
        $server = $_ENV['PDNS_SERVER_ID'] ?? 'localhost';
        if ($base === '' || $key === '') return [false, 'pdns not configured'];
        $url = rtrim($base, '/') . "/api/v1/servers/{$server}/" . ltrim($path, '/');
        $ch = curl_init($url);
        $headers = ['X-API-Key: ' . $key, 'Content-Type: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CUSTOMREQUEST=>$method,
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_TIMEOUT=>15,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
        $out = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($out === false) return [false, curl_error($ch)];
        return [$code >= 200 && $code < 300, $out];
    }

    public static function ensureZone(string $zoneName): bool {
        // zoneName must end with a dot
        if (substr($zoneName, -1) !== '.') $zoneName .= '.';
        [$ok, $out] = self::api('GET', 'zones/' . urlencode($zoneName));
        if ($ok) return true;
        $payload = [
            'name' => $zoneName,
            'kind' => 'Native',
            'rrsets' => [
                ['name'=>$zoneName, 'type'=>'SOA', 'ttl'=>3600, 'changetype'=>'REPLACE',
                    'records'=>[['content'=>'ns1.' . $zoneName . ' hostmaster.' . $zoneName . ' 1 10800 3600 604800 3600', 'disabled'=>false]]],
                ['name'=>$zoneName, 'type'=>'NS', 'ttl'=>3600, 'changetype'=>'REPLACE',
                    'records'=>[['content'=>'ns1.' . $zoneName, 'disabled'=>false]]],
            ],
        ];
        [$ok2, $out2] = self::api('POST', 'zones', $payload);
        return $ok2;
    }

    private static function reverseZoneForIPv4(string $ip): string {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) return '';
        return $parts[2] . '.' . $parts[1] . '.' . $parts[0] . '.in-addr.arpa.';
    }

    private static function ptrNameForIPv4(string $ip): string {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) return '';
        return $parts[3] . '.' . $parts[2] . '.' . $parts[1] . '.' . $parts[0] . '.in-addr.arpa.';
    }

    public static function setPTR(string $ip, string $targetFQDN): void {
        // Only IPv4 handled here
        $zone = self::reverseZoneForIPv4($ip);
        if ($zone === '') return;
        self::ensureZone($zone);
        if (substr($targetFQDN, -1) !== '.') $targetFQDN .= '.';
        $name = self::ptrNameForIPv4($ip);
        $rr = [
            'rrsets' => [[
                'name' => $name,
                'type' => 'PTR',
                'ttl'  => 300,
                'changetype' => 'REPLACE',
                'records' => [['content' => $targetFQDN, 'disabled'=>false]]
            ]]
        ];
        self::api('PATCH', 'zones/' . urlencode($zone), $rr);
    }
}
