<?php
namespace VMForge\Controllers;

use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\DB;
use VMForge\Core\Security;
use VMForge\Models\Job;
use PDO;

class FirewallController {
    public function index() {
        Auth::require();
        $pdo = DB::pdo();
        $vms = $pdo->query('SELECT uuid,name,node_id,firewall_mode FROM vm_instances ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        $rules = $pdo->query('SELECT fr.*, v.name AS vm_name FROM firewall_rules fr LEFT JOIN vm_instances v ON v.uuid=fr.vm_uuid ORDER BY v.name ASC, fr.priority ASC, fr.id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $csrf = Security::csrfToken();

        ob_start(); ?>
<div class="card">
  <h2>Firewall (ingress)</h2>
  <p>Mode per VM: <code>disabled</code> = no filtering, <code>allowlist</code> = drop by default, allow rules open ports; <code>denylist</code> = allow by default, deny rules close ports.</p>
  <form method="post" action="/admin/firewall">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
    <input type="hidden" name="action" value="mode">
    <label>VM</label>
    <select name="vm_uuid" required>
      <?php foreach ($vms as $v) { ?>
        <option value="<?php echo htmlspecialchars($v['uuid']); ?>"><?php echo htmlspecialchars($v['name'].' ('.$v['firewall_mode'].')'); ?></option>
      <?php } ?>
    </select>
    <select name="mode" required>
      <option value="disabled">disabled</option>
      <option value="allowlist">allowlist</option>
      <option value="denylist">denylist</option>
    </select>
    <button type="submit">Set mode + Apply</button>
  </form>
</div>

<div class="card">
  <h3>Add rule</h3>
  <form method="post" action="/admin/firewall">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
    <input type="hidden" name="action" value="rule">
    <label>VM</label>
    <select name="vm_uuid" required>
      <?php foreach ($vms as $v) { ?>
        <option value="<?php echo htmlspecialchars($v['uuid']); ?>"><?php echo htmlspecialchars($v['name']); ?></option>
      <?php } ?>
    </select>
    <label>Protocol</label>
    <select name="protocol">
      <option>tcp</option><option>udp</option><option>icmp</option><option>any</option>
    </select>
    <label>Source CIDR (optional)</label>
    <input name="source_cidr" placeholder="any or 198.51.100.0/24 or 2001:db8::/64">
    <label>Dest ports</label>
    <input name="dest_ports" placeholder="80,443 or 1000-2000 or any" value="80,443">
    <label>Action</label>
    <select name="action"><option>allow</option><option>deny</option></select>
    <label>Priority</label>
    <input type="number" name="priority" value="1000">
    <button type="submit">Add + Apply</button>
  </form>
</div>

<div class="card">
  <h3>Rules</h3>
  <table class="table">
    <thead><tr><th>VM</th><th>Proto</th><th>Source</th><th>Ports</th><th>Action</th><th>Priority</th><th>Enabled</th></tr></thead>
    <tbody>
      <?php foreach ($rules as $r) { ?>
        <tr>
          <td><?php echo htmlspecialchars($r['vm_name'] ?? $r['vm_uuid']); ?></td>
          <td><?php echo htmlspecialchars($r['protocol']); ?></td>
          <td><?php echo htmlspecialchars($r['source_cidr']); ?></td>
          <td><?php echo htmlspecialchars($r['dest_ports']); ?></td>
          <td><?php echo htmlspecialchars($r['action']); ?></td>
          <td><?php echo (int)$r['priority']; ?></td>
          <td><?php echo (int)$r['enabled'] ? 'yes' : 'no'; ?></td>
        </tr>
      <?php } ?>
    </tbody>
  </table>
</div>
<?php
        $html = ob_get_clean();
        View::render('Firewall', $html);
    }

    public function store() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $pdo = DB::pdo();
        $action = $_POST['action'] ?? 'rule';
        if ($action === 'mode') {
            $uuid = $_POST['vm_uuid'] ?? '';
            $mode = $_POST['mode'] ?? 'disabled';
            if ($uuid === '' || !in_array($mode, ['disabled','allowlist','denylist'], true)) { http_response_code(400); echo 'bad params'; return; }
            $st = $pdo->prepare('UPDATE vm_instances SET firewall_mode=? WHERE uuid=?');
            $st->execute([$mode, $uuid]);
            // enqueue sync
            $node = $pdo->prepare('SELECT node_id, name FROM vm_instances WHERE uuid=?'); $node->execute([$uuid]); $vm = $node->fetch(PDO::FETCH_ASSOC);
            if ($vm) { \VMForge\Models\Job::enqueue((int)$vm['node_id'], 'FW_SYNC', ['uuid'=>$uuid, 'name'=>$vm['name']]); }
            header('Location: /admin/firewall');
            return;
        }
        // add rule
        $uuid = $_POST['vm_uuid'] ?? '';
        $proto = $_POST['protocol'] ?? 'tcp';
        $src = trim($_POST['source_cidr'] ?? 'any'); if ($src === '') $src = 'any';
        $ports = trim($_POST['dest_ports'] ?? 'any'); if ($ports === '') $ports = 'any';
        $act = $_POST['action'] ?? 'allow';
        $prio = (int)($_POST['priority'] ?? 1000);
        if ($uuid === '' || !in_array($proto, ['tcp','udp','icmp','any'], true) || !in_array($act, ['allow','deny'], true)) { http_response_code(400); echo 'bad params'; return; }
        $ins = $pdo->prepare('INSERT INTO firewall_rules(vm_uuid,protocol,source_cidr,dest_ports,action,priority,enabled) VALUES (?,?,?,?,?,?,1)');
        $ins->execute([$uuid,$proto,$src,$ports,$act,$prio]);
        // enqueue sync
        $node = $pdo->prepare('SELECT node_id, name FROM vm_instances WHERE uuid=?'); $node->execute([$uuid]); $vm = $node->fetch(PDO::FETCH_ASSOC);
        if ($vm) { \VMForge\Models\Job::enqueue((int)$vm['node_id'], 'FW_SYNC', ['uuid'=>$uuid, 'name'=>$vm['name']]); }
        header('Location: /admin/firewall');
    }
}
