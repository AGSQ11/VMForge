<?php
namespace VMForge\Core;

use VMForge\Models\User;
use VMForge\Services\TwoFactorAuth;

class Auth {
    private const SESSION_TIMEOUT = 3600; // 1 hour
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_DURATION = 900; // 15 minutes
    
    /**
     * Authenticate user with email and password
     */
    public static function attempt(string $email, string $password, ?string $totpCode = null): array {
        $user = User::findByEmail($email);
        
        if (!$user) {
            self::logFailedAttempt($email);
            return ['success' => false, 'error' => 'Invalid credentials'];
        }
        
        // Check if account is locked
        if (self::isAccountLocked($user['id'])) {
            return ['success' => false, 'error' => 'Account temporarily locked due to multiple failed attempts'];
        }
        
        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            self::registerFailure($user['id']);
            return ['success' => false, 'error' => 'Invalid credentials'];
        }
        
        // Check if password needs rehashing
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            User::updatePassword($user['id'], $password);
        }
        
        // Verify 2FA if enabled
        if (!empty($user['totp_secret'])) {
            if (!$totpCode) {
                return ['success' => false, 'requires_2fa' => true];
            }
            
            if (!TwoFactorAuth::verify($user['totp_secret'], $totpCode)) {
                self::registerFailure($user['id']);
                return ['success' => false, 'error' => 'Invalid 2FA code'];
            }
        }
        
        // Successful authentication
        self::clearFailedAttempts($user['id']);
        self::loginUser($user['id']);
        
        // Log the successful login
        self::logSuccessfulLogin($user['id']);
        
        return ['success' => true, 'user' => $user];
    }
    
    /**
     * Create a new session for the authenticated user
     */
    public static function loginUser(int $userId): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        // Regenerate session ID to prevent fixation attacks
        session_regenerate_id(true);
        
        $_SESSION['uid'] = $userId;
        $_SESSION['fingerprint'] = self::generateFingerprint();
        $_SESSION['last_activity'] = time();
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Load user permissions into session
        $permissions = User::getPermissions($userId);
        $_SESSION['permissions'] = $permissions;
        
        // Check if user has admin role
        $_SESSION['is_admin'] = User::hasRole($userId, 'admin');
    }
    
    /**
     * Verify current session is valid
     */
    public static function check(): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        if (empty($_SESSION['uid'])) {
            return false;
        }
        
        // Check session timeout
        if (isset($_SESSION['last_activity'])) {
            if (time() - $_SESSION['last_activity'] > self::SESSION_TIMEOUT) {
                self::logout();
                return false;
            }
        }
        
        // Verify session fingerprint
        if (!self::verifyFingerprint()) {
            self::logout();
            return false;
        }
        
        // Update last activity
        $_SESSION['last_activity'] = time();
        
        return true;
    }
    
    /**
     * Get current authenticated user
     */
    public static function user(): ?array {
        if (!self::check()) {
            return null;
        }
        
        $user = User::findById($_SESSION['uid']);
        if ($user) {
            $user['permissions'] = $_SESSION['permissions'] ?? [];
            $user['is_admin'] = $_SESSION['is_admin'] ?? false;
        }
        
        return $user;
    }
    
    /**
     * Get current user ID
     */
    public static function id(): ?int {
        return self::check() ? ($_SESSION['uid'] ?? null) : null;
    }
    
    /**
     * Require authentication or redirect to login
     */
    public static function require(): void {
        if (!self::check()) {
            header('Location: /login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
            exit;
        }
    }
    
    /**
     * Require specific permission
     */
    public static function requirePermission(string $permission): void {
        self::require();
        
        if (!Policy::can($permission)) {
            http_response_code(403);
            View::render('Forbidden', '<div class="card"><h2>403 Forbidden</h2><p>You do not have permission to access this resource.</p></div>');
            exit;
        }
    }
    
    /**
     * Destroy current session
     */
    public static function logout(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        $_SESSION = [];
        
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        
        session_destroy();
    }
    
    /**
     * Generate session fingerprint
     */
    private static function generateFingerprint(): string {
        $data = [
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            self::getIpPrefix()
        ];
        
        return hash('sha256', implode('|', $data));
    }
    
    /**
     * Verify session fingerprint
     */
    private static function verifyFingerprint(): bool {
        $current = self::generateFingerprint();
        $stored = $_SESSION['fingerprint'] ?? '';
        
        return hash_equals($stored, $current);
    }
    
    /**
     * Get IP address prefix for fingerprinting
     */
    private static function getIpPrefix(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // Use /24 for IPv4
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0';
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Use /64 for IPv6
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 4)) . '::';
        }
        
        return '';
    }
    
    /**
     * Register failed login attempt
     */
    private static function registerFailure(int $userId): void {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare('
            UPDATE users 
            SET failed_logins = failed_logins + 1,
                last_failed_login = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$userId]);
        
        // Check if we need to lock the account
        $user = User::findById($userId);
        if ($user && $user['failed_logins'] >= self::MAX_LOGIN_ATTEMPTS) {
            $stmt = $pdo->prepare('
                UPDATE users 
                SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
                WHERE id = ?
            ');
            $stmt->execute([self::LOCKOUT_DURATION, $userId]);
        }
    }
    
    /**
     * Check if account is locked
     */
    private static function isAccountLocked(int $userId): bool {
        $user = User::findById($userId);
        
        if (!$user || empty($user['locked_until'])) {
            return false;
        }
        
        return strtotime($user['locked_until']) > time();
    }
    
    /**
     * Clear failed login attempts
     */
    private static function clearFailedAttempts(int $userId): void {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare('
            UPDATE users 
            SET failed_logins = 0,
                locked_until = NULL
            WHERE id = ?
        ');
        $stmt->execute([$userId]);
    }
    
    /**
     * Log failed login attempt
     */
    private static function logFailedAttempt(string $email): void {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare('
            INSERT INTO audit_log (user_id, action, ip, details, created_at)
            VALUES (NULL, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            'login_failed',
            $_SERVER['REMOTE_ADDR'] ?? '',
            json_encode(['email' => $email])
        ]);
    }
    
    /**
     * Log successful login
     */
    private static function logSuccessfulLogin(int $userId): void {
        // Update user record
        $pdo = DB::pdo();
        $stmt = $pdo->prepare('
            UPDATE users 
            SET last_login_at = NOW(),
                last_login_ip = ?
            WHERE id = ?
        ');
        $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '', $userId]);
        
        // Add audit log entry
        $stmt = $pdo->prepare('
            INSERT INTO audit_log (user_id, action, ip, details, created_at)
            VALUES (?, ?, ?, NULL, NOW())
        ');
        $stmt->execute([
            $userId,
            'login_success',
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    }
}
