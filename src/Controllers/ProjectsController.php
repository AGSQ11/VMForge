<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\DB;
use VMForge\Core\View;
use VMForge\Core\Policy;
use VMForge\Core\Security;
use PDO;

class ProjectsController {
    public function index() {
        Auth::require();
        $pdo = DB::pdo();
        $projects = $pdo->query('SELECT * FROM projects ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $rows = '';
        $csrf = Security::csrfToken();
        foreach ($projects as $p) {
            $rows .= '<tr><td>'.(int)$p['id'].'</td><td>'.htmlspecialchars($p['name']).'</td><td>
                <form method="post" action="/admin/projects/switch" style="display:inline">
                    <input type="hidden" name="csrf" value="'.$csrf.'">
                    <input type="hidden" name="project_id" value="'.(int)$p['id'].'">
                    <button type="submit">Switch</button>
                </form>
                </td></tr>';
        }
        $sel = Policy::currentProjectId();
        $html = '<div class="card"><h2>Projects</h2>
        <p>Current: <strong>'.($sel ? (int)$sel : 'none').'</strong></p>
        <table class="table"><thead><tr><th>ID</th><th>Name</th><th>Actions</th></tr></thead><tbody>'.$rows.'</tbody></table></div>';
        $html .= '<div class="card"><h3>Create Project</h3>
        <form method="post" action="/admin/projects">
            <input type="hidden" name="csrf" value="'.$csrf.'">
            <input name="name" placeholder="project name" required>
            <button type="submit">Create</button>
        </form></div>';
        $html .= '<div class="card"><h3>Set Quotas</h3>
        <form method="post" action="/admin/projects/quotas">
            <input type="hidden" name="csrf" value="'.$csrf.'">
            <input name="project_id" type="number" placeholder="project id" required>
            <input name="max_vms" type="number" placeholder="max vms">
            <input name="max_vcpus" type="number" placeholder="max vcpus">
            <input name="max_ram_mb" type="number" placeholder="max ram mb">
            <input name="max_disk_gb" type="number" placeholder="max disk gb">
            <button type="submit">Save</button>
        </form></div>';
        View::render('Projects', $html);
    }
    public function store() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $name = trim($_POST['name'] ?? '');
        if ($name === '') { http_response_code(400); echo 'name required'; return; }
        $pdo = DB::pdo();
        $pdo->prepare('INSERT INTO projects(name) VALUES (?)')->execute([$name]);
        $pid = (int)$pdo->lastInsertId();
        $user = Auth::user();
        $pdo->prepare('INSERT INTO user_projects(user_id, project_id, role) VALUES (?,?,?)')->execute([ (int)$user['id'], $pid, 'owner' ]);
        header('Location: /admin/projects');
    }
    public function switch() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $pid = (int)($_POST['project_id'] ?? 0);
        if ($pid) {
            $user = Auth::user();
            Policy::requireMember($user, $pid);
            $_SESSION['project_id'] = $pid;
        } else {
            $_SESSION['project_id'] = null;
        }
        header('Location: /admin/projects');
    }
    public function quotas() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $pid = (int)($_POST['project_id'] ?? 0);
        $user = Auth::user();
        Policy::ensureOwner($user, $pid);
        $pdo = DB::pdo();
        $st = $pdo->prepare('INSERT INTO quotas(project_id, max_vms, max_vcpus, max_ram_mb, max_disk_gb) VALUES (?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE max_vms=VALUES(max_vms), max_vcpus=VALUES(max_vcpus), max_ram_mb=VALUES(max_ram_mb), max_disk_gb=VALUES(max_disk_gb)');
        $st->execute([$pid,
            $_POST['max_vms'] !== '' ? (int)$_POST['max_vms'] : null,
            $_POST['max_vcpus'] !== '' ? (int)$_POST['max_vcpus'] : null,
            $_POST['max_ram_mb'] !== '' ? (int)$_POST['max_ram_mb'] : null,
            $_POST['max_disk_gb'] !== '' ? (int)$_POST['max_disk_gb'] : null
        ]);
        header('Location: /admin/projects');
    }
}
