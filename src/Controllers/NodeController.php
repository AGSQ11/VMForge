<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\UUID;
use VMForge\Models\Node;

class NodeController {
    public function index() {
        Auth::require();
        \VMForge\Core\Security::requireCsrf($_POST['csrf'] ?? null);
        $nodes = Node::all();
        $rows = '';
        foreach ($nodes as $n) {
            $rows .= '<tr><td>'.htmlspecialchars((string)$n['id']).'</td><td>'.htmlspecialchars($n['name']).'</td><td>'.htmlspecialchars($n['mgmt_url']).'</td><td><span class="badge">'.htmlspecialchars($n['bridge']).'</span></td></tr>';
        }
        $html = '<div class="card"><h2>Nodes</h2>
        <table class="table"><thead><tr><th>ID</th><th>Name</th><th>Mgmt URL</th><th>Bridge</th></tr></thead><tbody>'.$rows.'</tbody></table>
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
        $token = bin2hex(random_bytes(16));
        $id = Node::create([
            'name' => $_POST['name'] ?? 'node',
            'mgmt_url' => $_POST['mgmt_url'] ?? '',
            'bridge' => $_POST['bridge'] ?? 'br0',
            'token' => $token
        ]);
        header('Location: /admin/nodes');
    }
}
