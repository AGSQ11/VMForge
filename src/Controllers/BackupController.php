<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\DB;
use VMForge\Models\Job;
use PDO;

class BackupController {
    public function index() {
        Auth::require();
        $pdo = DB::pdo();
        $bk = $pdo->query('SELECT * FROM backups ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        $rows='';
        foreach ($bk as $b) {
            $rows .= '<tr><td>'.(int)$b['id'].'</td><td>'.htmlspecialchars($b['vm_uuid']).'</td><td>'.(int)$b['node_id'].'</td><td>'.htmlspecialchars($b['snapshot_name']).'</td><td>'.htmlspecialchars($b['location']).'</td><td>'.htmlspecialchars($b['created_at']).'</td></tr>';
        }
        $html = '<div class="card"><h2>Backups</h2><table class="table"><thead><tr><th>ID</th><th>VM</th><th>Node</th><th>Snapshot</th><th>Location</th><th>Created</th></tr></thead><tbody>'.$rows.'</tbody></table></div>';
        View::render('Backups', $html);
    }
}
