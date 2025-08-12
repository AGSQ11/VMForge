<?php
namespace VMForge\Controllers;

use VMForge\Core\DB;
use VMForge\Core\View;
use VMForge\Core\Password;
use VMForge\Core\Security;
use VMForge\Models\User;
use VMForge\Models\Role;

class SetupController
{
    public function showSetupForm()
    {
        $csrf = Security::csrfToken();
        $html = '<div class="card" style="margin-top: 50px; max-width: 400px; margin-left: auto; margin-right: auto;"><h2>Welcome to VMForge Setup</h2>';
        $html .= '<p>Please create the initial administrator account.</p>';
        $html .= '<form method="post" action="/setup">';
        $html .= '<input type="hidden" name="csrf" value="' . $csrf . '">';
        $html .= '<label for="email">Email Address</label><input type="email" name="email" id="email" required>';
        $html .= '<label for="password">Password</label><input type="password" name="password" id="password" required>';
        $html .= '<button type="submit">Create Admin Account</button>';
        $html .= '</form></div>';
        View::render('Initial Setup', $html);
    }

    public function createAdminUser()
    {
        // Double-check that there are still no users
        if (DB::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            header('Location: /login');
            exit;
        }

        Security::requireCsrf($_POST['csrf'] ?? null);
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Simple validation
            header('Location: /setup');
            exit;
        }

        // Create the user
        $hash = Password::hash($password);
        $st = DB::pdo()->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
        $st->execute([$email, $hash]);
        $userId = (int)DB::pdo()->lastInsertId();

        // Assign the 'admin' role
        $adminRole = Role::findByName('admin');
        if ($adminRole) {
            User::assignRole($userId, (int)$adminRole['id']);
        }

        header('Location: /login');
        exit;
    }
}
