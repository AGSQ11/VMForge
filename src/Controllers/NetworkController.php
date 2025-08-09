<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\DB;
use VMForge\Models\Job;
use PDO;

class NetworkController {
    public function index() {
        Auth::require();
        $pdo = DB::pdo();
        $nodes = $pdo->query('SELECT id,name,mgmt_url,bridge FROM nodes ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $opts='';
        foreach ($nodes as $n) {
            $opts .= '<option value="'.(int)$n['id'].'">'.htmlspecialchars($n['name']).' (bridge='.htmlspecialchars($n['bridge']).')</option>';
        }
        $html = '<div class="card"><h2>Network</h2>
        <form method="post" action="/admin/network">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(\VMForge\Core\Security::csrfToken()); ?>">
            <label>Node</label><select name="node_id" required>'.$opts.'</select>
            <label>Mode</label><select name="mode"><option value="nat">NAT</option><option value="routed">Routed</option></select>
            <input name="bridge" placeholder="br0" value="br0" required>
            <input name="wan_iface" placeholder="eth0">
            <button type="submit">Apply</button>
        </form>
        <p>This writes basic nftables rules for forwarding and (if NAT) masquerade. Harden as needed.</p>
        </div>';
        View::render('Network', $html);
    }
    public function store() {
        Auth::require();
        \VMForge\Core\Security::requireCsrf($_POST['csrf'] ?? null);
        $node = (int)($_POST['node_id'] ?? 0);
        $mode = $_POST['mode'] ?? 'nat';
        $bridge = $_POST['bridge'] ?? 'br0';
        $wan = $_POST['wan_iface'] ?? null;
        Job::enqueue($node, 'NET_SETUP', ['mode'=>$mode,'bridge'=>$bridge,'wan_iface'=>$wan]);
        header('Location: /admin/network');
    }
}
