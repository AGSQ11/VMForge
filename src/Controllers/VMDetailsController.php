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
        $actions = ['KVM_START','KVM_STOP','KVM_REBOOT','KVM_DELETE'] if ($vm['type']==='kvm') else ['LXC_START','LXC_STOP','LXC_DELETE'];
        $btns = '';
        foreach ($actions as $a) {
            $btns .= '<button type="submit" name="action" value="'.$a.'">'.$a.'</button> ';
        }
        $html = '<div class="card"><h2>VM '.htmlspecialchars($vm['name']).'</h2>
        <p>UUID: '.htmlspecialchars($vm['uuid']).'</p>
        <p>Type: '.htmlspecialchars($vm['type']).' | Node: '.(int)$vm['node_id'].'</p>
        <form method="post" action="/admin/vm-action">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(\VMForge\Core\Security::csrfToken()); ?>">
            <input type="hidden" name="uuid" value="'.htmlspecialchars($vm['uuid']).'">
            <input type="hidden" name="name" value="'.htmlspecialchars($vm['name']).'">
            <input type="hidden" name="node_id" value="'.(int)$vm['node_id'].'">
            '.$btns.'
        </form>
        <p><a href="/console/open?uuid='.htmlspecialchars($vm['uuid']).'">Open Console</a></p>
        </div>';
        View::render('VM Details', $html);
    }
    public function action() {
        Auth::require();
        \VMForge\Core\Security::requireCsrf($_POST['csrf'] ?? null);
        $uuid = $_POST['uuid'] ?? ''; $name = $_POST['name'] ?? ''; $node = (int)($_POST['node_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        if (!$uuid || !$name || !$node || !$action) { http_response_code(400); echo 'missing'; return; }
        $type = str_starts_with($action, 'KVM_') ? 'kvm' : 'lxc';
        Job::enqueue($node, $action, ['name'=>$name]);
        header('Location: /admin/vms');
    }
}
