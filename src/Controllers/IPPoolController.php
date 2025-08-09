<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\DB;
use PDO;

class IPPoolController {
    public function index() {
        Auth::require();
        $pools = DB::pdo()->query('SELECT * FROM ip_pools ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        $rows='';
        foreach ($pools as $p) {
            $rows .= '<tr><td>'.(int)$p['id'].'</td><td>'.htmlspecialchars($p['name']).'</td><td>'.htmlspecialchars($p['cidr']).'</td><td>'.htmlspecialchars($p['gateway'] ?? '').'</td><td>'.htmlspecialchars($p['dns'] ?? '').'</td><td>'.(int)$p['version'].'</td></tr>';
        }
        $html = '<div class="card"><h2>IP Pools</h2>
        <table class="table"><thead><tr><th>ID</th><th>Name</th><th>CIDR</th><th>Gateway</th><th>DNS</th><th>Ver</th></tr></thead><tbody>'.$rows.'</tbody></table>
        </div>
        <div class="card"><h3>Add Pool</h3>
        <form method="post" action="/admin/ip-pools">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(\VMForge\Core\Security::csrfToken()); ?>">
            <input name="name" placeholder="lab-v4" required>
            <input name="cidr" placeholder="192.0.2.0/24" required>
            <input name="gateway" placeholder="192.0.2.1">
            <input name="dns" placeholder="1.1.1.1,8.8.8.8">
            <select name="version"><option value="4">4</option><option value="6">6</option></select>
            <button type="submit">Create</button>
        </form></div>';
        View::render('IP Pools', $html);
    }
    public function store() {
        Auth::require();
        \VMForge\Core\Security::requireCsrf($_POST['csrf'] ?? null);
        $st = DB::pdo()->prepare('INSERT INTO ip_pools (name,cidr,gateway,dns,version) VALUES (?,?,?,?,?)');
        $st->execute([
            $_POST['name'] ?? 'pool',
            $_POST['cidr'] ?? '',
            $_POST['gateway'] ?? null,
            $_POST['dns'] ?? null,
            (int)($_POST['version'] ?? 4)
        ]);
        header('Location: /admin/ip-pools');
    }
}
