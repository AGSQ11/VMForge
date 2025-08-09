<?php
namespace VMForge\Controllers;
use VMForge\Core\View;
use VMForge\Core\Auth;

class AuthController {
    public function showLogin() {
        $html = '<div class="card"><h2>Login</h2>
        <form method="post" action="/login">
            <input name="email" type="email" placeholder="email" required>
            <input name="password" type="password" placeholder="password" required>
            <button type="submit">Login</button>
        </form></div>';
        if (!empty($_SESSION['2fa_uid'])) {
            $html .= '<div class="card"><h3>One-Time Code</h3>
            <form method="post" action="/login?otp=1">
                <input name="code" placeholder="123456" pattern="\d{6}" required>
                <button type="submit">Verify</button>
            </form></div>';
        }
        View::render('Login', $html);
    }
    public function login() {
        if (isset($_GET['otp'])) {
            $ok = Auth::verifyTotp($_POST['code'] ?? '');
            if ($ok) { header('Location: /'); return; }
            header('Location: /login'); return;
        }
        $ok = Auth::login($_POST['email'] ?? '', $_POST['password'] ?? '');
        if ($ok && empty($_SESSION['2fa_uid'])) { header('Location: /'); return; }
        header('Location: /login'); return;
    }
    public function logout() {
        Auth::logout();
        header('Location: /login');
    }
}
