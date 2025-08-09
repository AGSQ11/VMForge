<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\Security;
use VMForge\Services\ISOStore;

class ISOController {
    public function index() {
        Auth::require();
        $isos = ISOStore::all();
        $csrf = Security::csrfToken();

        ob_start();
        ?>
<div class="card">
  <h2>ISO Library</h2>
  <table class="table">
    <thead><tr><th>ID</th><th>Name</th><th>URL</th><th>Checksum</th><th>Local</th></tr></thead>
    <tbody>
      <?php foreach ($isos as $i) { ?>
        <tr>
          <td><?php echo (int)$i['id']; ?></td>
          <td><?php echo htmlspecialchars($i['name']); ?></td>
          <td><code><?php echo htmlspecialchars($i['url']); ?></code></td>
          <td><code><?php echo htmlspecialchars((string)$i['checksum']); ?></code></td>
          <td><code><?php echo htmlspecialchars((string)$i['local_path']); ?></code></td>
        </tr>
      <?php } ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>Add ISO</h3>
  <form method="post" action="/admin/isos">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
    <input name="name" placeholder="Ubuntu 24.04 live" required>
    <input name="url" placeholder="https://releases.ubuntu.com/.../ubuntu-24.04.iso" required style="width: 40em;">
    <input name="checksum" placeholder="sha256:abcd... (optional)">
    <button type="submit">Add</button>
  </form>
</div>
        <?php
        $html = ob_get_clean();
        View::render('ISOs', $html);
    }

    public function store() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $name = trim($_POST['name'] ?? '');
        $url  = trim($_POST['url'] ?? '');
        $sum  = trim($_POST['checksum'] ?? '');
        if ($name === '' || $url === '') { http_response_code(400); echo 'missing name/url'; return; }
        ISOStore::add($name, $url, $sum !== '' ? $sum : null);
        header('Location: /admin/isos');
    }
}
