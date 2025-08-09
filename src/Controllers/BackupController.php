<?php
namespace VMForge\Controllers;

use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\DB;
use VMForge\Core\Security;
use VMForge\Models\Job;
use PDO;

class BackupController {
    public function index() {
        Auth::require();
        $pdo = DB::pdo();
        $jobs = $pdo->query("SELECT id, node_id, type, status, created_at FROM jobs WHERE type LIKE 'ZFS_%' ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        $vms = $pdo->query("SELECT uuid,name,node_id FROM vm_instances ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $repos = $pdo->query("SELECT id,name FROM zfs_repos ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $csrf = Security::csrfToken();

        $vmOptions = '';
        foreach ($vms as $v) { $vmOptions .= '<option value="'.$v['uuid'].'" data-node="'.$v['node_id'].'">'.htmlspecialchars($v['name']).'</option>'; }
        $repoOptions = '';
        foreach ($repos as $r) { $repoOptions .= '<option value="'.$r['id'].'">'.(int)$r['id'].' — '.htmlspecialchars($r['name']).'</option>'; }

        ob_start(); ?>
<div class="card">
  <h2>ZFS Backups</h2>
  <form method="post" action="/admin/backups">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
    <label>VM</label>
    <select name="vm_uuid" required><?php echo $vmOptions; ?></select>
    <label>Repo</label>
    <select name="repo_id" required><?php echo $repoOptions; ?></select>
    <label>Retention — keep last N snapshots</label>
    <input type="number" name="keep_last" min="1" value="7">
    <button type="submit" name="action" value="create">Create backup</button>
    <button type="submit" name="action" value="prune">Enforce retention</button>
  </form>
</div>

<div class="card">
  <h3>Recent ZFS backup jobs</h3>
  <table class="table">
    <thead><tr><th>ID</th><th>Node</th><th>Type</th><th>Status</th><th>Created</th></tr></thead>
    <tbody>
      <?php foreach ($jobs as $j) { ?>
      <tr>
        <td><?php echo (int)$j['id']; ?></td>
        <td><?php echo (int)$j['node_id']; ?></td>
        <td><?php echo htmlspecialchars($j['type']); ?></td>
        <td><?php echo htmlspecialchars($j['status']); ?></td>
        <td><?php echo htmlspecialchars($j['created_at']); ?></td>
      </tr>
      <?php } ?>
    </tbody>
  </table>
</div>
<?php
        $html = ob_get_clean();
        View::render('Backups', $html);
    }

    public function create() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $action = $_POST['action'] ?? 'create';
        $uuid = $_POST['vm_uuid'] ?? ''; if ($uuid === '') { http_response_code(400); echo 'missing vm_uuid'; return; }
        $repo = (int)($_POST['repo_id'] ?? 0); if ($repo < 1) { http_response_code(400); echo 'missing repo_id'; return; }
        $keep = (int)($_POST['keep_last'] ?? 7);
        $pdo = DB::pdo();
        $st = $pdo->prepare("SELECT node_id, name FROM vm_instances WHERE uuid=? LIMIT 1");
        $st->execute([$uuid]);
        $vm = $st->fetch(PDO::FETCH_ASSOC);
        if (!$vm) { http_response_code(404); echo 'vm not found'; return; }
        $payload = ['uuid'=>$uuid,'name'=>$vm['name'],'repo_id'=>$repo,'keep_last'=>$keep];
        if ($action === 'prune') {
            Job::enqueue((int)$vm['node_id'], 'ZFS_PRUNE', $payload);
        } else {
            Job::enqueue((int)$vm['node_id'], 'ZFS_BACKUP', $payload);
        }
        header('Location: /admin/backups');
    }
}
