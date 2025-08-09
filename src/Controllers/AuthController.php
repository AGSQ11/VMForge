<?php
namespace VMForge\Controllers;

use VMForge\Core\Auth;
use VMForge\Core\Security;
use VMForge\Core\View;

class AuthController {
    public function showLogin() {
        if (Auth::check()) { header('Location: /'); return; }
        $csrf = Security::csrfToken();
        ob_start(); ?>
<div class="card narrow">
  <h2>Login</h2>
  <form method="post" action="/login">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="email" name="email" placeholder="email" required autofocus>
    <input type="password" name="password" placeholder="password" required>
    <button type="submit">Login</button>
  </form>
</div>
<?php
        $html = ob_get_clean();
        View::render('Login', $html);
    }

    public function login() {
        Security::requireCsrf($_POST['csrf'] ?? null);
        $email = trim($_POST['email'] ?? '');
        $pass  = (string)($_POST['password'] ?? '');
        if ($email === '' || $pass === '') { http_response_code(400); echo 'missing credentials'; return; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo 'bad email'; return; }

        if (Auth::attempt($email, $pass)) {
            header('Location: /'); return;
        }
        // generic failure (no user enumeration)
        http_response_code(401); echo 'invalid credentials';
    }

    public function logout() {
        Auth::logout();
        header('Location: /login');
    }
}
