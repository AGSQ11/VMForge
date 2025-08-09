<?php
namespace VMForge\Core;

class Security {
    public static function startSecureSession(): void {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function csrfToken(): string {
        self::startSecureSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function requireCsrf(?string $token): void {
        self::startSecureSession();
        if (!$token || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
            http_response_code(419);
            die('CSRF token mismatch');
        }
    }

    public static function hashToken(string $token): string {
        return hash('sha256', $token);
    }
}
