<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\DB;
use VMForge\Models\Job;
use PDO;

class VMDetailsController {
    public function show() {
        Auth::require();
        $uuid = $_GET['uuid'] ?? '';
        if (!$uuid) { http_response_code(400); echo 'missing uuid'; return; }
        $pdo = DB::pdo();
        $st = $pdo->prepare('SELECT * FROM vm_instances WHERE uuid=? LIMIT 1');
        $st->execute([$uuid]);
        $vm = $st->fetch(PDO::FETCH_ASSOC);
        if (!$vm) { http_response_code(404); echo 'not found'; return; }
        $actions = ($vm['type']==='kvm') ? ['KVM_START','KVM_STOP','KVM_REBOOT','KVM_DELETE'] : ['LXC_START','LXC_STOP','LXC_DELETE'];
        $btns = '';
        foreach ($actions as $a) { $btns .= '<button type="submit" name="action" value="'.$a.'">'.$a.'</button> '; }
        // list backups for this VM
        $bk = $pdo->prepare('SELECT * FROM backups WHERE vm_uuid=? ORDER BY id DESC');
        $bk->execute([$uuid]);
        $rows='';
        foreach ($bk->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $rows .= '<tr><td>'.(int)$b['id'].'</td><td>'.htmlspecialchars($b['snapshot_name']).'</td><td>'.htmlspecialchars($b['location']).'</td><td><form method="post" action="/admin/restore"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(\VMForge\Core\Security::csrfToken()); ?>"><input type="hidden" name="node_id" value="'.(int)$vm['node_id'].'"><input type="hidden" name="vm_name" value="'.htmlspecialchars($vm['name']).'"><input type="hidden" name="source" value="'.htmlspecialchars($b['location']).'"><button type="submit">Restore</button></form></td></tr>';
        }
        $html = '<div class="card"><h2>VM '.htmlspecialchars($vm['name']).'</h2>
        <p>UUID: '.htmlspecialchars($vm['uuid']).'</p>
        <p>Type: '.htmlspecialchars($vm['type']).' | Node: '.(int)$vm['node_id'].' | Project: '.(int)$vm['project_id'].'</p>
        <form method="post" action="/admin/vm-action">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(\VMForge\Core\Security::csrfToken()); ?>">
            <input type="hidden" name="uuid" value="'.htmlspecialchars($vm['uuid']).'">
            <input type="hidden" name="name" value="'.htmlspecialchars($vm['name']).'">
            <input type="hidden" name="node_id" value="'.(int)$vm['node_id'].'">
            '.$btns.'
        </form>
        <p><a href="/console/open?uuid='.htmlspecialchars($vm['uuid']).'">Open Console</a></p>
        </div>
        <div class="card"><h3>Backups</h3><table class="table"><thead><tr><th>ID</th><th>Snapshot</th><th>Location</th><th>Action</th></tr></thead><tbody>'.$rows.'</tbody></table></div>';
        View::render('VM Details', $html);
    }
    public function action() {
        Auth::require();
        \VMForge\Core\Security::requireCsrf($_POST['csrf'] ?? null);
        $uuid = $_POST['uuid'] ?? ''; $name = $_POST['name'] ?? ''; $node = (int)($_POST['node_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        if (!$uuid || !$name || !$node || !$action) { http_response_code(400); echo 'missing'; return; }
        Job::enqueue($node, $action, ['name'=>$name]);
        header('Location: /admin/vms');
    }
}
