<?php
namespace VMForge\Core;

class Security {
    private const CSRF_TOKEN_LENGTH = 32;
    private const TOKEN_EXPIRY = 3600; // 1 hour
    
    /**
     * Generate CSRF token
     */
    public static function csrfToken(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        // Clean up expired tokens
        self::cleanExpiredTokens();
        
        // Generate new token
        $token = bin2hex(random_bytes(self::CSRF_TOKEN_LENGTH));
        
        if (!isset($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }
        
        $_SESSION['csrf_tokens'][$token] = time() + self::TOKEN_EXPIRY;
        
        return $token;
    }
    
    /**
     * Validate CSRF token
     */
    public static function validateCsrf(?string $token): bool {
        if (empty($token)) {
            return false;
        }
        
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        if (!isset($_SESSION['csrf_tokens'][$token])) {
            return false;
        }
        
        $expiry = $_SESSION['csrf_tokens'][$token];
        
        if ($expiry < time()) {
            unset($_SESSION['csrf_tokens'][$token]);
            return false;
        }
        
        // Token is valid, remove it (one-time use)
        unset($_SESSION['csrf_tokens'][$token]);
        
        return true;
    }
    
    /**
     * Require valid CSRF token or terminate request
     */
    public static function requireCsrf(?string $token): void {
        if (!self::validateCsrf($token)) {
            http_response_code(403);
            die('CSRF token validation failed');
        }
    }
    
    /**
     * Clean up expired CSRF tokens
     */
    private static function cleanExpiredTokens(): void {
        if (!isset($_SESSION['csrf_tokens'])) {
            return;
        }
        
        $now = time();
        $_SESSION['csrf_tokens'] = array_filter(
            $_SESSION['csrf_tokens'],
            function ($expiry) use ($now) {
                return $expiry > $now;
            }
        );
    }
    
    /**
     * Hash API token for storage
     */
    public static function hashToken(string $token): string {
        return hash('sha256', $token);
    }
    
    /**
     * Generate secure random token
     */
    public static function generateToken(int $length = 32): string {
        return bin2hex(random_bytes($length));
    }
    
    /**
     * Sanitize input string
     */
    public static function sanitize(string $input, int $flags = ENT_QUOTES | ENT_HTML5): string {
        return htmlspecialchars(trim($input), $flags, 'UTF-8');
    }
    
    /**
     * Validate email address
     */
    public static function validateEmail(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate IP address
     */
    public static function validateIp(string $ip, bool $allowPrivate = false): bool {
        $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6;
        
        if (!$allowPrivate) {
            $flags |= FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP, $flags) !== false;
    }
    
    /**
     * Validate UUID v4
     */
    public static function validateUuid(string $uuid): bool {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        return preg_match($pattern, $uuid) === 1;
    }
    
    /**
     * Rate limiting check
     */
    public static function checkRateLimit(string $key, int $maxAttempts = 10, int $window = 60): bool {
        $pdo = DB::pdo();
        
        // Clean old entries
        $stmt = $pdo->prepare('
            DELETE FROM rate_limits 
            WHERE rl_key = ? AND bucket_start < DATE_SUB(NOW(), INTERVAL ? SECOND)
        ');
        $stmt->execute([$key, $window]);
        
        // Check current count
        $stmt = $pdo->prepare('
            SELECT COUNT(*) as cnt 
            FROM rate_limits 
            WHERE rl_key = ? AND bucket_start > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ');
        $stmt->execute([$key, $window]);
        $count = (int)$stmt->fetchColumn();
        
        if ($count >= $maxAttempts) {
            return false;
        }
        
        // Record attempt
        $stmt = $pdo->prepare('
            INSERT INTO rate_limits (rl_key, bucket_start, count)
            VALUES (?, NOW(), 1)
            ON DUPLICATE KEY UPDATE count = count + 1
        ');
        $stmt->execute([$key]);
        
        return true;
    }
    
    /**
     * Encrypt sensitive data
     */
    public static function encrypt(string $data, ?string $key = null): string {
        $key = $key ?? Env::get('APP_SECRET', 'default-change-me');
        $cipher = 'aes-256-gcm';
        $iv = random_bytes(16);
        $tag = '';
        
        $encrypted = openssl_encrypt(
            $data,
            $cipher,
            hash('sha256', $key, true),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        return base64_encode($iv . $tag . $encrypted);
    }
    
    /**
     * Decrypt sensitive data
     */
    public static function decrypt(string $encrypted, ?string $key = null): ?string {
        $key = $key ?? Env::get('APP_SECRET', 'default-change-me');
        $cipher = 'aes-256-gcm';
        
        $data = base64_decode($encrypted);
        if ($data === false) {
            return null;
        }
        
        $iv = substr($data, 0, 16);
        $tag = substr($data, 16, 16);
        $ciphertext = substr($data, 32);
        
        $decrypted = openssl_decrypt(
            $ciphertext,
            $cipher,
            hash('sha256', $key, true),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        return $decrypted !== false ? $decrypted : null;
    }
    
    /**
     * Validate hostname
     */
    public static function validateHostname(string $hostname): bool {
        return preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $hostname) === 1;
    }
    
    /**
     * Validate MAC address
     */
    public static function validateMac(string $mac): bool {
        return preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac) === 1;
    }
}
