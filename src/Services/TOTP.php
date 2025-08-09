<?php
namespace VMForge\Services;
class TOTP {
    public static function generateSecret(int $bytes = 20): string {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
    public static function hotp(string $secret, int $counter, int $digits = 6): string {
        $key = base64_decode(strtr($secret, '-_', '+/'));
        $bin_counter = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $bin_counter, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncated = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string)($truncated % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }
    public static function verify(string $secret, string $code, int $window = 1): bool {
        $t = floor(time() / 30);
        for ($i=-$window; $i <= $window; $i++) {
            if (hash_equals(self::hotp($secret, $t + $i), preg_replace('/\s+/', '', $code))) return true;
        }
        return false;
    }
    public static function otpauthUrl(string $label, string $issuer, string $secret): string {
        $label = rawurlencode($label);
        $issuer = rawurlencode($issuer);
        return "otpauth://totp/{$issuer}:{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }
}
