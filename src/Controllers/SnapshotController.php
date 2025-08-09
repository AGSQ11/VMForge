<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\DB;
use VMForge\Models\Job;
use PDO;

class SnapshotController {
    public function create() {
        Auth::require();
        $uuid = $_POST['uuid'] ?? '';
        $name = $_POST['name'] ?? 'snap-' . date('Ymd-His');
        $node = (int)($_POST['node_id'] ?? 0);
        if (!$uuid || !$node) { http_response_code(400); echo 'missing'; return; }
        Job::enqueue($node, 'SNAPSHOT_CREATE', ['name'=>$_POST['vm_name'] ?? $uuid, 'snapshot'=>$name]);
        header('Location: /admin/vms');
    }
}
