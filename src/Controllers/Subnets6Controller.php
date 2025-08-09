<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\DB;
use VMForge\Core\Security;
use VMForge\Services\IPAM6;
use PDO;

class Subnets6Controller {
    public function index() {
        Auth::require();
        $pdo = DB::pdo();
        $rows = $pdo->query('SELECT s.*, p.name AS project_name FROM subnets6 s LEFT JOIN projects p ON p.id = s.project_id ORDER BY s.id DESC')->fetchAll(PDO::FETCH_ASSOC);
        $csrf = Security::csrfToken();

        ob_start(); ?>
<div class="card">
  <h2>IPv6 Subnets</h2>
  <table class="table">
    <thead><tr><th>ID</th><th>Name</th><th>Prefix</th><th>Gateway</th><th>RAs</th><th>DNS</th><th>VLAN</th><th>Project</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r) { ?>
        <tr>
          <td><?php echo (int)$r['id']; ?></td>
          <td><?php echo htmlspecialchars($r['name']); ?></td>
          <td><?php echo htmlspecialchars($r['prefix']); ?></td>
          <td><?php echo htmlspecialchars((string)$r['gateway_ip6']); ?></td>
          <td><?php echo ((int)$r['ra_enabled']) ? 'on' : 'off'; ?></td>
          <td><?php echo htmlspecialchars((string)$r['dns_servers']); ?></td>
          <td><?php echo htmlspecialchars((string)$r['vlan_tag']); ?></td>
          <td><?php echo htmlspecialchars($r['project_name'] ?? ''); ?></td>
        </tr>
      <?php } ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>Add IPv6 Subnet</h3>
  <form method="post" action="/admin/subnets6">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
    <input name="name" placeholder="name" required>
    <input name="prefix" placeholder="2001:db8:1234::/64" required>
    <input name="dns_servers" placeholder="(optional) 2001:4860:4860::8888 2001:4860:4860::8844">
    <input name="project_id" placeholder="(optional) project id">
    <input name="vlan_tag" placeholder="(optional) vlan tag">
    <label><input type="checkbox" name="ra_enabled" value="1" checked> Enable Router Advertisements</label>
    <button type="submit">Create</button>
  </form>
</div>
<?php
        $html = ob_get_clean();
        View::render('IPv6 Subnets', $html);
    }

    public function store() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $name = trim($_POST['name'] ?? '');
        $prefix = trim($_POST['prefix'] ?? '');
        $dns = trim($_POST['dns_servers'] ?? '');
        $pid  = $_POST['project_id'] !== '' ? (int)$_POST['project_id'] : null;
        $vlan = $_POST['vlan_tag'] !== '' ? (int)$_POST['vlan_tag'] : null;
        $ra   = isset($_POST['ra_enabled']) ? 1 : 0;

        if ($name === '' || $prefix === '') { http_response_code(400); echo 'missing name/prefix'; return; }
        $gw = \VMForge\Services\IPAM6::gatewayFromPrefix64($prefix);
        if ($gw === null) { http_response_code(400); echo 'prefix must be a valid /64'; return; }

        $st = DB::pdo()->prepare('INSERT INTO subnets6(name,prefix,project_id,vlan_tag,gateway_ip6,ra_enabled,dns_servers) VALUES (?,?,?,?,?,?,?)');
        $st->execute([$name, $prefix, $pid, $vlan, $gw, $ra, $dns !== '' ? $dns : null]);
        header('Location: /admin/subnets6');
    }
}
