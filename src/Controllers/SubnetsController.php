<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\DB;
use VMForge\Core\Security;
use PDO;

class SubnetsController {
    public function index() {
        Auth::require();
        $pdo = DB::pdo();
        $rows = $pdo->query('SELECT s.*, p.name AS project_name FROM subnets s LEFT JOIN projects p ON p.id = s.project_id ORDER BY s.id DESC')->fetchAll(PDO::FETCH_ASSOC);
        $csrf = Security::csrfToken();

        ob_start(); ?>
<div class="card">
  <h2>Subnets</h2>
  <table class="table">
    <thead><tr><th>ID</th><th>Name</th><th>CIDR</th><th>Gateway</th><th>VLAN</th><th>Project</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r) { ?>
        <tr>
          <td><?php echo (int)$r['id']; ?></td>
          <td><?php echo htmlspecialchars($r['name']); ?></td>
          <td><?php echo htmlspecialchars($r['cidr']); ?></td>
          <td><?php echo htmlspecialchars((string)$r['gateway_ip']); ?></td>
          <td><?php echo htmlspecialchars((string)$r['vlan_tag']); ?></td>
          <td><?php echo htmlspecialchars($r['project_name'] ?? ''); ?></td>
        </tr>
      <?php } ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>Add Subnet</h3>
  <form method="post" action="/admin/subnets">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
    <input name="name" placeholder="name" required>
    <input name="cidr" placeholder="198.51.100.0/24" required>
    <input name="gateway_ip" placeholder="(optional) gateway IP">
    <input name="project_id" placeholder="(optional) project id">
    <input name="vlan_tag" placeholder="(optional) vlan tag">
    <button type="submit">Create</button>
  </form>
</div>
<?php
        $html = ob_get_clean();
        View::render('Subnets', $html);
    }

    public function store() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $name = trim($_POST['name'] ?? '');
        $cidr = trim($_POST['cidr'] ?? '');
        $gw   = trim($_POST['gateway_ip'] ?? '');
        $pid  = $_POST['project_id'] !== '' ? (int)$_POST['project_id'] : null;
        $vlan = $_POST['vlan_tag'] !== '' ? (int)$_POST['vlan_tag'] : null;
        if ($name === '' || $cidr === '') { http_response_code(400); echo 'missing name/cidr'; return; }
        // calculate default gateway if not given (first host)
        if ($gw === '') {
            if (preg_match('~^(\d+)\.(\d+)\.(\d+)\.(\d+)/(\d+)$~', $cidr, $m)) {
                $netLong = (ip2long($m[1].'.'.$m[2].'.'.$m[3].'.'.$m[4]) & (-1 << (32 - (int)$m[5])));
                $gw = long2ip($netLong + 1);
            }
        }
        $st = DB::pdo()->prepare('INSERT INTO subnets(name,cidr,project_id,vlan_tag,gateway_ip) VALUES (?,?,?,?,?)');
        $st->execute([$name, $cidr, $pid, $vlan, $gw !== '' ? $gw : null]);
        header('Location: /admin/subnets');
    }
}
