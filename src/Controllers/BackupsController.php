<?php
namespace VMForge\Controllers;

use VMForge\Core\Auth;
use VMForge\Core\Security;
use VMForge\Core\View;
use VMForge\Core\DB;
use VMForge\Services\Backup;

class BackupsController {
    public function index() {
        Auth::require();
        $pdo = DB::pdo();
        $vms = $pdo->query('SELECT uuid, name FROM vm_instances ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);
        $vm = $_GET['vm'] ?? ($vms[0]['uuid'] ?? null);
        $backups = $vm ? Backup::list($vm) : [];
        $csrf = Security::csrfToken();

        ob_start(); ?>
<div class="card">
  <h2>Backups</h2>
  <form method="get" action="/admin/backups" style="margin-bottom:1rem">
    <select name="vm" onchange="this.form.submit()">
      <?php foreach ($vms as $vv): ?>
        <option value="<?= htmlspecialchars($vv['uuid']) ?>" <?= $vm===$vv['uuid']?'selected':'' ?>><?= htmlspecialchars($vv['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php if ($vm): ?>
    <form method="post" action="/admin/backups/create">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="vm" value="<?= htmlspecialchars($vm) ?>">
      <button type="submit">Create Backup Now</button>
    </form>
    <table class="table" style="margin-top:1rem">
      <thead><tr><th>ID</th><th>Created</th><th>Size</th><th>Storage</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($backups as $b): ?>
        <tr>
          <td><?= (int)$b['id'] ?></td>
          <td><?= htmlspecialchars($b['created_at']) ?></td>
          <td><?= number_format((int)$b['size_bytes']/1048576, 1) ?> MiB</td>
          <td><?= htmlspecialchars($b['storage']) ?></td>
          <td><?= htmlspecialchars($b['status']) ?></td>
          <td>
            <form method="post" action="/admin/backups/delete" onsubmit="return confirm('Delete backup #<?= (int)$b['id'] ?>?')">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
              <button type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php
        $html = ob_get_clean();
        View::render('Backups', $html);
    }

    public function create() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $vm = $_POST['vm'] ?? null;
        if (!$vm) { http_response_code(400); echo 'missing vm'; return; }
        $pdo = DB::pdo();
        $st = $pdo->prepare('SELECT uuid, name FROM vm_instances WHERE uuid=?');
        $st->execute([$vm]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) { http_response_code(404); echo 'vm not found'; return; }
        try {
            Backup::backupVM((string)$row['name'], (string)$row['uuid']);
            header('Location: /admin/backups?vm=' . urlencode($row['uuid']));
        } catch (\Throwable $e) {
            http_response_code(500);
            echo 'backup failed: ' . htmlspecialchars($e->getMessage());
        }
    }

    public function delete() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) { http_response_code(400); echo 'bad id'; return; }
        Backup::delete($id);
        header('Location: /admin/backups');
    }
}
