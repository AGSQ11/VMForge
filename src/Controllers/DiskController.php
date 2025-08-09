<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\Security;
use VMForge\Models\Job;

class DiskController {
    public function resize() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $node = (int)($_POST['node_id'] ?? 0);
        $name = $_POST['vm_name'] ?? '';
        $size = (int)($_POST['new_disk_gb'] ?? 0);
        if (!$node || !$name || $size < 1) { http_response_code(400); echo 'missing'; return; }
        Job::enqueue($node, 'DISK_RESIZE', ['name'=>$name,'new_gb'=>$size]);
        header('Location: /admin/vms');
    }
}
