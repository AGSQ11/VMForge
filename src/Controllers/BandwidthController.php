<?php
namespace VMForge\Controllers;

use VMForge\Core\Auth;
use VMForge\Core\Security;
use VMForge\Core\View;
use VMForge\Core\DB;

class BandwidthController {
    public function index() {
        Auth::require();
        $pdo = DB::pdo();
        $vms = $pdo->query('SELECT uuid, name FROM vm_instances ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);
        $vm = $_GET['vm'] ?? ($vms[0]['uuid'] ?? null);
        $cap = null;
        if ($vm) {
            $st = $pdo->prepare('SELECT mbps FROM egress_caps WHERE vm_uuid=?');
            $st->execute([$vm]);
            $cap = $st->fetchColumn();
        }
        $stats = [];
        if ($vm) {
            $st = $pdo->prepare('SELECT SUM(rx_bytes) AS rx, SUM(tx_bytes) AS tx FROM bandwidth_usage WHERE vm_uuid=? AND period_start > (NOW() - INTERVAL 1 DAY)');
            $st->execute([$vm]);
            $agg = $st->fetch(\PDO::FETCH_ASSOC) ?: ['rx'=>0,'tx'=>0];
            $stats = ['day_rx'=>(int)$agg['rx'], 'day_tx'=>(int)$agg['tx']];
        }
        $csrf = Security::csrfToken();

        ob_start(); ?>
<div class="card">
  <h2>Bandwidth</h2>
  <form method="get" action="/admin/bandwidth" style="margin-bottom:1rem">
    <select name="vm" onchange="this.form.submit()">
      <?php foreach ($vms as $vv): ?>
        <option value="<?= htmlspecialchars($vv['uuid']) ?>" <?= $vm===$vv['uuid']?'selected':'' ?>><?= htmlspecialchars($vv['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if ($vm): ?>
  <div style="display:flex; gap:2rem; align-items:center; margin-bottom:1rem">
    <div><b>24h RX:</b> <?= number_format(($stats['day_rx']??0)/1024/1024,1) ?> MiB</div>
    <div><b>24h TX:</b> <?= number_format(($stats['day_tx']??0)/1024/1024,1) ?> MiB</div>
    <div><b>Egress cap:</b> <?= $cap ? (int)$cap . ' Mbps' : 'none' ?></div>
  </div>

  <form method="post" action="/admin/bandwidth/setcap" style="display:flex; gap:0.5rem; align-items:center">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="vm" value="<?= htmlspecialchars($vm) ?>">
    <input type="number" min="1" step="1" name="mbps" placeholder="Mbps" required>
    <button type="submit">Set Egress Cap</button>
  </form>

  <form method="post" action="/admin/bandwidth/clearcap" style="margin-top:0.5rem" onsubmit="return confirm('Clear cap?')">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="vm" value="<?= htmlspecialchars($vm) ?>">
    <button type="submit">Clear Cap</button>
  </form>
  <?php endif; ?>
</div>
<?php
        $html = ob_get_clean();
        View::render('Bandwidth', $html);
    }

    public function setcap() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $vm = $_POST['vm'] ?? null;
        $mbps = (int)($_POST['mbps'] ?? 0);
        if (!$vm || $mbps < 1) { http_response_code(400); echo 'bad input'; return; }

        $pdo = DB::pdo();
        $pdo->prepare('REPLACE INTO egress_caps (vm_uuid, mbps, updated_at) VALUES (?, ?, NOW())')->execute([$vm, $mbps]);

        $st = $pdo->prepare('SELECT node_id, name FROM vm_instances WHERE uuid=?');
        $st->execute([$vm]); $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) { http_response_code(404); echo 'vm not found'; return; }

        $payload = json_encode(['name'=>$row['name'], 'mbps'=>$mbps], JSON_UNESCAPED_SLASHES);
        $ins = $pdo->prepare("INSERT INTO jobs (node_id, type, payload, status, created_at) VALUES (?, 'NET_EGRESS_CAP_SET', ?, 'pending', NOW())");
        $ins->execute([(int)$row['node_id'], $payload]);

        header('Location: /admin/bandwidth?vm=' . urlencode($vm));
    }

    public function clearcap() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $vm = $_POST['vm'] ?? null;
        if (!$vm) { http_response_code(400); echo 'bad input'; return; }

        $pdo = DB::pdo();
        $pdo->prepare('DELETE FROM egress_caps WHERE vm_uuid=?')->execute([$vm]);
        $st = $pdo->prepare('SELECT node_id, name FROM vm_instances WHERE uuid=?');
        $st->execute([$vm]); $row = $st->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $payload = json_encode(['name'=>$row['name']], JSON_UNESCAPED_SLASHES);
            $ins = $pdo->prepare("INSERT INTO jobs (node_id, type, payload, status, created_at) VALUES (?, 'NET_EGRESS_CAP_CLEAR', ?, 'pending', NOW())");
            $ins->execute([(int)$row['node_id'], $payload]);
        }
        header('Location: /admin/bandwidth?vm=' . urlencode($vm));
    }
}
