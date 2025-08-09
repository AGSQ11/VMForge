<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\DB;
use VMForge\Core\View;
use VMForge\Services\TOTP;
use PDO;

class SettingsController {
    public function twofa() {
        Auth::require();
        $user = Auth::user();
        $pdo = DB::pdo();
        $st = $pdo->prepare('SELECT totp_secret FROM users WHERE id=?');
        $st->execute([(int)$user['id']]);
        $secret = $st->fetchColumn();
        $otpauth = $secret ? TOTP::otpauthUrl($user['email'], $_ENV['APP_NAME'] ?? 'VMForge', $secret) : '';
        $html = '<div class="card"><h2>Two-Factor Auth (TOTP)</h2>';
        if ($secret) {
            $html .= '<p>2FA is <strong>enabled</strong>.</p><p>otpauth URL (import into your authenticator app):<br><code>'.htmlspecialchars($otpauth).'</code></p>
            <form method="post" action="/settings/2fa?disable=1"><button type="submit">Disable</button></form>';
        } else {
            $html .= '<p>2FA is <strong>disabled</strong>.</p>
            <form method="post" action="/settings/2fa?enable=1"><button type="submit">Enable</button></form>';
        }
        $html .= '</div>';
        View::render('2FA', $html);
    }
    public function twofaPost() {
        Auth::require();
        $user = Auth::user();
        $pdo = DB::pdo();
        if (isset($_GET['enable'])) {
            $secret = TOTP::generateSecret();
            $pdo->prepare('UPDATE users SET totp_secret=? WHERE id=?')->execute([$secret, (int)$user['id']]);
        } elseif (isset($_GET['disable'])) {
            $pdo->prepare('UPDATE users SET totp_secret=NULL WHERE id=?')->execute([(int)$user['id']]);
        }
        header('Location: /settings/2fa');
    }
}
