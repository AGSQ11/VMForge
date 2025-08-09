<?php
namespace VMForge\Controllers;
use VMForge\Core\View;
use VMForge\Models\User;

class AuthController {
    public function showLogin() {
        $html = '<div class="card"><h2>Login</h2>
        <form method="post" action="/login">
        <div><input name="email" placeholder="Email" required></div><br>
        <div><input type="password" name="password" placeholder="Password" required></div><br>
        <button type="submit">Login</button>
        </form></div>';
        View::render('Login', $html);
    }
    public function login() {
        session_start();
        $email = $_POST['email'] ?? '';
        $pass = $_POST['password'] ?? '';
        $u = User::findByEmail($email);
        if ($u && password_verify($pass, $u['password_hash'])) {
            $_SESSION['uid'] = $u['id'];
            header('Location: /'); exit;
        }
        header('Location: /login');
    }
    public function logout() {
        session_start(); session_destroy();
        header('Location: /login');
    }
}
