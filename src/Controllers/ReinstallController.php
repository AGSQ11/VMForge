<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\Security;
use VMForge\Services\ISOStore;
use VMForge\Models\Job;

class ReinstallController {
    public function create() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $node = (int)($_POST['node_id'] ?? 0);
        $name = $_POST['vm_name'] ?? '';
        $isoId = (int)($_POST['iso_id'] ?? 0);
        if (!$node || !$name || !$isoId) { http_response_code(400); echo 'missing'; return; }
        // We'll let the agent download/cache ISO if needed
        Job::enqueue($node, 'KVM_REINSTALL', ['name'=>$name, 'iso_id'=>$isoId]);
        header('Location: /admin/vms');
    }
}
