<?php
namespace VMForge\Core;
use PDO;

class Auth {
    public static function require(): void {
        self::initSession();
        if (empty($_SESSION['uid'])) {
            header('Location: /login'); exit;
        }
    }
    public static function user(): ?array {
        self::initSession();
        if (empty($_SESSION['uid'])) return null;
        $st = DB::pdo()->prepare('SELECT id, email, is_admin, totp_secret FROM users WHERE id=?');
        $st->execute([ (int)$_SESSION['uid'] ]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public static function login(string $email, string $password): bool {
        self::initSession();
        $pdo = DB::pdo();
        $st = $pdo->prepare('SELECT id, email, password_hash, totp_secret, failed_logins, lock_until FROM users WHERE email=? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) { self::audit(null,'login_fail', $email); return false; }
        if (!empty($u['lock_until']) && strtotime($u['lock_until']) > time()) return false;
        $ok = false;
        $hash = $u['password_hash'] ?: '';
        if (preg_match('/^\$2y\$|^\$argon2/', $hash)) {
            $ok = password_verify($password, $hash);
        } elseif (preg_match('/^[a-f0-9]{64}$/i', $hash)) {
            $ok = hash_equals($hash, hash('sha256', $password));
            if ($ok) {
                $new = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$new, (int)$u['id']]);
            }
        }
        if (!$ok) {
            $fails = (int)$u['failed_logins'] + 1;
            $lockUntil = null;
            if ($fails >= 5) { $lockUntil = date('Y-m-d H:i:s', time()+300); $fails = 0; }
            $pdo->prepare('UPDATE users SET failed_logins=?, lock_until=? WHERE id=?')->execute([$fails, $lockUntil, (int)$u['id']]);
            self::audit((int)$u['id'],'login_fail',$email);
            return false;
        }
        if (!empty($u['totp_secret'])) {
            $_SESSION['2fa_uid'] = (int)$u['id'];
            return true;
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $pdo->prepare('UPDATE users SET last_login_at=NOW(), last_login_ip=? WHERE id=?')->execute([$_SERVER['REMOTE_ADDR'] ?? null, (int)$u['id']]);
        self::audit((int)$u['id'],'login_ok',$email);
        return true;
    }
    public static function verifyTotp(string $code): bool {
        self::initSession();
        if (empty($_SESSION['2fa_uid'])) return false;
        $uid = (int)$_SESSION['2fa_uid'];
        $st = DB::pdo()->prepare('SELECT totp_secret FROM users WHERE id=?');
        $st->execute([$uid]);
        $secret = (string)$st->fetchColumn();
        if (!$secret) return false;
        if (\VMForge\Services\TOTP::verify($secret, $code)) {
            session_regenerate_id(true);
            $_SESSION['uid'] = $uid;
            unset($_SESSION['2fa_uid']);
            DB::pdo()->prepare('UPDATE users SET last_login_at=NOW(), last_login_ip=? WHERE id=?')->execute([$_SERVER['REMOTE_ADDR'] ?? null, $uid]);
            self::audit($uid,'login_2fa_ok','');
            return true;
        }
        return false;
    }
    public static function logout(): void {
        self::initSession();
        session_destroy();
        setcookie(session_name(), '', time()-3600, '/', '', true, true);
    }
    private static function initSession(): void {
        static $inited = false;
        if ($inited) return;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([ 'secure'=>$secure, 'httponly'=>true, 'samesite'=>'Strict', 'path'=>'/' ]);
        session_start();
        $inited = true;
    }
    private static function audit($uid, string $action, string $details): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        DB::pdo()->prepare('INSERT INTO audit_log(user_id, action, ip, details) VALUES (?,?,?,?)')->execute([$uid, $action, $ip, $details]);
    }
}
