<?php
namespace VMForge\Controllers;

use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\DB;
use VMForge\Core\Security;
use PDO;

class ZFSReposController {
    public function index() {
        Auth::require();
        $pdo = DB::pdo();
        $rows = $pdo->query("SELECT * FROM zfs_repos ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $csrf = Security::csrfToken();
        ob_start(); ?>
<div class="card">
  <h2>ZFS Backup Repos</h2>
  <table class="table">
    <thead><tr><th>ID</th><th>Name</th><th>Mode</th><th>Dataset</th><th>Remote</th><th>Compression</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r) { ?>
      <tr>
        <td><?php echo (int)$r['id']; ?></td>
        <td><?php echo htmlspecialchars($r['name']); ?></td>
        <td><?php echo htmlspecialchars($r['mode']); ?></td>
        <td><code><?php echo htmlspecialchars($r['dataset']); ?></code></td>
        <td><?php echo htmlspecialchars($r['remote_user'] ? ($r['remote_user'].'@') : ''); echo htmlspecialchars($r['remote_host'] ?? ''); ?></td>
        <td><?php echo htmlspecialchars($r['compression']); ?></td>
      </tr>
    <?php } ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>Add Repo</h3>
  <form method="post" action="/admin/zfs-repos">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
    <input name="name" placeholder="name" required>
    <select name="mode">
      <option value="local">local</option>
      <option value="ssh">ssh</option>
    </select>
    <input name="dataset" placeholder="tank/vmforge-backups" required>
    <input name="remote_user" placeholder="(ssh) user">
    <input name="remote_host" placeholder="(ssh) host">
    <input name="ssh_port" placeholder="22">
    <select name="compression">
      <option>lz4</option>
      <option>zstd</option>
      <option>off</option>
    </select>
    <button type="submit">Create</button>
  </form>
</div>
<?php
        $html = ob_get_clean();
        View::render('ZFS Repos', $html);
    }

    public function store() {
        Auth::require();
        \VMForge\Core\Security::requireCsrf($_POST['csrf'] ?? null);
        $name = trim($_POST['name'] ?? '');
        $mode = $_POST['mode'] ?? 'local';
        $dataset = trim($_POST['dataset'] ?? '');
        $remote_user = trim($_POST['remote_user'] ?? '');
        $remote_host = trim($_POST['remote_host'] ?? '');
        $ssh_port = $_POST['ssh_port'] !== '' ? (int)$_POST['ssh_port'] : null;
        $compression = $_POST['compression'] ?? 'lz4';
        if ($name === '' || $dataset === '') { http_response_code(400); echo 'missing'; return; }
        $st = DB::pdo()->prepare("INSERT INTO zfs_repos(name,mode,dataset,remote_user,remote_host,ssh_port,compression) VALUES (?,?,?,?,?,?,?)");
        $st->execute([$name, $mode, $dataset, $remote_user ?: null, $remote_host ?: null, $ssh_port, $compression]);
        header('Location: /admin/zfs-repos');
    }
}
