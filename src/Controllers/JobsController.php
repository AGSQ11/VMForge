<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\DB;
use PDO;

class JobsController {
    public function index() {
        Auth::require();
        $pdo = DB::pdo();
        $jobs = $pdo->query('SELECT id,node_id,type,status,created_at,started_at,finished_at,LEFT(log,150) AS log FROM jobs ORDER BY id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
        $rows='';
        foreach ($jobs as $j) {
            $rows .= '<tr><td>'.(int)$j['id'].'</td><td>'.(int)$j['node_id'].'</td><td>'.htmlspecialchars($j['type']).'</td><td>'.htmlspecialchars($j['status']).'</td><td>'.htmlspecialchars($j['created_at']).'</td><td>'.htmlspecialchars($j['started_at']).'</td><td>'.htmlspecialchars($j['finished_at']).'</td><td><pre>'.htmlspecialchars($j['log']).'</pre></td></tr>';
        }
        $html = '<div class="card"><h2>Jobs</h2><table class="table"><thead><tr><th>ID</th><th>Node</th><th>Type</th><th>Status</th><th>Created</th><th>Started</th><th>Finished</th><th>Log</th></tr></thead><tbody>'.$rows.'</tbody></table></div>';
        View::render('Jobs', $html);
    }
}
