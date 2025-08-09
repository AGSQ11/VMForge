<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\DB;
use PDO;

class NodeController {
    public function index() {
        Auth::require();
        $pdo = DB::pdo();
        $nodes = $pdo->query('SELECT * FROM nodes ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $rows='';
        foreach ($nodes as $n) {
            $seen = $n['last_seen'] ?? null;
            $status = ($seen && (time()-strtotime($seen) < 120)) ? '<span style="color:#22c55e">online</span>' : '<span style="color:#ef4444">offline</span>';
            $rows .= '<tr><td>'.(int)$n['id'].'</td><td>'.htmlspecialchars($n['name']).'</td><td>'.htmlspecialchars($n['mgmt_url']).'</td><td>'.htmlspecialchars($n['bridge']).'</td><td>'.$status.'</td><td>'.htmlspecialchars($seen ?: '').'</td></tr>';
        }
        $html = '<div class="card"><h2>Nodes</h2>
        <table class="table"><thead><tr><th>ID</th><th>Name</th><th>Mgmt URL</th><th>Bridge</th><th>Status</th><th>Last Seen</th></tr></thead><tbody>'.$rows.'</tbody></table>
        </div>
        <div class="card"><h3>Add Node</h3>
        <form method="post" action="/admin/nodes">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(\VMForge\Core\Security::csrfToken()); ?>">
            <input name="name" placeholder="node name" required>
            <input name="mgmt_url" placeholder="https://node1.example/api" required>
            <input name="bridge" placeholder="br0" value="br0" required>
            <button type="submit">Create</button>
        </form></div>';
        View::render('Nodes', $html);
    }
    public function store() {
        Auth::require();
        \VMForge\Core\Security::requireCsrf($_POST['csrf'] ?? null);
        $token = bin2hex(random_bytes(16));
        $pdo = DB::pdo();
        $st = $pdo->prepare('INSERT INTO nodes (name, mgmt_url, bridge, token) VALUES (?,?,?,?)');
        $st->execute([$_POST['name'] ?? 'node', $_POST['mgmt_url'] ?? '', $_POST['bridge'] ?? 'br0', $token]);
        header('Location: /admin/nodes');
    }
}
