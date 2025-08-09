<?php
namespace VMForge\Core;
use VMForge\Models\User;
class Auth {
    public static function user(): ?array {
        session_start();
        if (!isset($_SESSION['uid'])) return null;
        $u = User::findById((int)$_SESSION['uid']);
        return $u ?: null;
    }
    public static function require(): void {
        if (!self::user()) {
            header('Location: /login'); exit;
        }
    }
}
