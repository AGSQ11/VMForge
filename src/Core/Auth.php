<?php
namespace VMForge\Core;

use VMForge\Core\DB;
use VMForge\Core\Password;
use PDO;

class Auth {
    public static function attempt(string $email, string $password): bool {
        $pdo = DB::pdo();
        $st = $pdo->prepare('SELECT id, email, password, password_hash, password_legacy, failed_logins, locked_until FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) return false;

        // lockout check
        if (!empty($u['locked_until'])) {
            $lockedUntil = strtotime((string)$u['locked_until']);
            if ($lockedUntil && time() < $lockedUntil) return false;
        }

        $ok = false;
        $rehash = false;

        // Preferred modern hash
        if (!empty($u['password_hash'])) {
            if (Password::verify($password, $u['password_hash'])) {
                $ok = true;
                $rehash = Password::needsRehash($u['password_hash']);
            }
        } else {
            // Legacy support: (1) bcrypt/argon2 stored in old 'password' column
            if (!empty($u['password']) && password_get_info((string)$u['password'])['algo'] !== 0) {
                if (password_verify($password, (string)$u['password'])) {
                    $ok = true;
                }
            }
            // Legacy support: (2) sha256 without salt (last resort)
            if (!$ok && !empty($u['password'])) {
                $legacy = (string)$u['password'];
                if (strlen($legacy) === 64 && ctype_xdigit($legacy)) {
                    $ok = hash_equals($legacy, hash('sha256', $password));
                }
            }
            // Legacy support: (3) password_legacy column as fallback (sha256 or old scheme)
            if (!$ok && !empty($u['password_legacy'])) {
                $L = (string)$u['password_legacy'];
                if (password_get_info($L)['algo'] !== 0) {
                    $ok = password_verify($password, $L);
                } elseif (strlen($L) === 64 && ctype_xdigit($L)) {
                    $ok = hash_equals($L, hash('sha256', $password));
                }
            }
        }

        if (!$ok) {
            self::registerFailure((int)$u['id']);
            return false;
        }

        // success: migrate to Argon2id, clear legacy, reset counters
        $hash = Password::hash($password);
        $upd = $pdo->prepare('UPDATE users SET password_hash=?, password=NULL, password_legacy=NULL, failed_logins=0, locked_until=NULL, last_login_at=NOW() WHERE id=?');
        $upd->execute([$hash, (int)$u['id']]);

        self::loginUser((int)$u['id']);
        return true;
    }

    public static function loginUser(int $userId): void {
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
        session_regenerate_id(true);
        $_SESSION['uid'] = $userId;
        $_SESSION['fp']  = self::fingerprint();
        $_SESSION['t']   = time();
    }

    public static function logout(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
        if (empty($_SESSION['uid'])) return false;
        if (($_SESSION['fp'] ?? '') !== self::fingerprint()) return false;
        return true;
    }

    public static function id(): ?int {
        if (!self::check()) return null;
        return (int)($_SESSION['uid'] ?? 0) ?: null;
    }

    public static function require(): void {
        if (!self::check()) {
            http_response_code(302);
            header('Location: /login');
            exit;
        }
    }

    private static function registerFailure(int $userId): void {
        $pdo = DB::pdo();
        $max = (int)Env::get('AUTH_MAX_FAILURES', '6');
        $lockSecs = (int)Env::get('AUTH_LOCK_SECONDS', '900');
        $upd = $pdo->prepare('UPDATE users SET failed_logins = failed_logins + 1 WHERE id=?');
        $upd->execute([$userId]);
        $row = $pdo->prepare('SELECT failed_logins FROM users WHERE id=?');
        $row->execute([$userId]);
        $fail = (int)$row->fetchColumn();
        if ($fail >= $max) {
            $lock = $pdo->prepare('UPDATE users SET locked_until = (NOW() + INTERVAL ? SECOND) WHERE id=?');
            $lock->execute([$lockSecs, $userId]);
        }
    }

    private static function fingerprint(): string {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        // Truncate ipv4 to /16, ipv6 to /48 to reduce churn
        if (strpos($ip, ':') !== false) {
            $parts = explode(':', $ip);
            $ipKey = implode(':', array_slice($parts, 0, 3));
        } else {
            $oct = explode('.', $ip);
            $ipKey = $oct[0] . '.' . ($oct[1] ?? '0');
        }
        return hash('sha256', $ua . '|' . $ipKey);
    }
}
