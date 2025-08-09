<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\DB;
use VMForge\Core\Security;
use VMForge\Services\Storage;
use PDO;

class StorageController {
    public function index() {
        Auth::require();
        $pools = Storage::all();
        $csrf = Security::csrfToken();

        $rows = '';
        foreach ($pools as $p) {
            $rows .= '<tr><td>'.(int)$p['id'].'</td><td>'.htmlspecialchars($p['name']).'</td><td>'.htmlspecialchars($p['driver']).'</td><td><code>'.htmlspecialchars($p['config']).'</code></td></tr>';
        }

        $table = '<div class="card"><h2>Storage Pools</h2><table class="table"><thead><tr><th>ID</th><th>Name</th><th>Driver</th><th>Config</th></tr></thead><tbody>'.$rows.'</tbody></table></div>';

        $form = <<<HTML
<div class="card"><h3>Add Pool</h3>
<form method="post" action="/admin/storage">
  <input type="hidden" name="csrf" value="{$csrf}">
  <input name="name" placeholder="name" required>
  <select name="driver">
    <option value="qcow2">qcow2</option>
    <option value="lvmthin">lvmthin</option>
    <option value="zfs">zfs</option>
  </select>
  <textarea name="config" placeholder='{"vg":"vg0","thinpool":"thinpool0"} OR {"pool":"tank","dataset":"vmforge"}'></textarea>
  <button type="submit">Create</button>
</form>
</div>
HTML;

        View::render('Storage', $table . $form);
    }

    public function store() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);

        $name = trim($_POST['name'] ?? '');
        if ($name === '') { http_response_code(400); echo 'name required'; return; }

        $driver = $_POST['driver'] ?? 'qcow2';
        $cfgRaw = $_POST['config'] ?? '';
        $cfg = ($cfgRaw !== '') ? json_decode($cfgRaw, true) : null;
        if ($cfgRaw !== '' && $cfg === null) { http_response_code(400); echo 'invalid JSON in config'; return; }

        Storage::createPool($name, $driver, $cfg);
        header('Location: /admin/storage');
    }
}
