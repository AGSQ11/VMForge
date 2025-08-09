<?php
namespace VMForge\Core;

class Headers {
    public static function sendSecurityHeaders(): void {
        // Basic hardening headers
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: no-referrer-when-downgrade');
        header('X-XSS-Protection: 0'); // modern browsers ignore; rely on CSP
        // Minimal, compatible CSP (adjust if you add inline scripts)
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; object-src 'none'; frame-ancestors 'self'; base-uri 'self'");
        // Strict-Transport-Security if behind HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
    }

    public static function initSession(): void {
        // Safer PHP session configuration
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_strict_mode', '1');
            session_start();
            // Fingerprint: user-agent + /24 (IPv4) or /64 (IPv6) prefix
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $fp = self::fingerprint($ua, $ip);
            if (!isset($_SESSION['_fp'])) {
                $_SESSION['_fp'] = $fp;
            } elseif ($_SESSION['_fp'] !== $fp) {
                session_regenerate_id(true);
                $_SESSION = [];
                $_SESSION['_fp'] = $fp;
            }
        }
    }

    private static function fingerprint(string $ua, string $ip): string {
        $prefix = $ip;
        if (strpos($ip, ':') !== false) { // IPv6
            $parts = explode(':', $ip);
            $prefix = implode(':', array_slice($parts, 0, 4)); // /64 approx
        } else { // IPv4
            $parts = explode('.', $ip);
            if (count($parts) === 4) $prefix = $parts[0].'.'.$parts[1].'.'.$parts[2].'.0';
        }
        return hash('sha256', $ua.'|'.$prefix);
    }
}
