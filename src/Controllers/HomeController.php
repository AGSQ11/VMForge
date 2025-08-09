<?php
namespace VMForge\Controllers;
use VMForge\Core\View;
use VMForge\Core\Auth;

class HomeController {
    public function index() {
        if (!Auth::user()) {
            header('Location: /login'); exit;
        }
        $html = '<div class="card"><h2>Dashboard</h2><p>Welcome to VMForge — an ENGINYRING project.</p></div>';
        View::render('Dashboard', $html);
    }
}
