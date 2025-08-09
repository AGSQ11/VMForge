<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\Security;
use VMForge\Models\Job;

class RestoreController {
    public function create() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $node = (int)($_POST['node_id'] ?? 0);
        $name = $_POST['vm_name'] ?? '';
        $src  = $_POST['source'] ?? '';
        if (!$node || !$name || !$src) { http_response_code(400); echo 'missing'; return; }
        Job::enqueue($node, 'BACKUP_RESTORE', ['name'=>$name, 'source'=>$src]);
        header('Location: /admin/vms');
    }
}
