<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\UUID;
use VMForge\Models\VM;
use VMForge\Models\Node;
use VMForge\Models\Job;

class VMController {
    public function index() {
        Auth::require();
        $vms = VM::all();
        $nodes = Node::all();
        $nodeOptions = '';
        foreach ($nodes as $n) {
            $nodeOptions .= '<option value="'.$n['id'].'">'.htmlspecialchars($n['name']).'</option>';
        }
        $rows = '';
        foreach ($vms as $v) {
            $rows .= '<tr><td>'.htmlspecialchars($v['uuid']).'</td><td>'.htmlspecialchars($v['name']).'</td><td>'.htmlspecialchars($v['type']).'</td><td>'.htmlspecialchars((string)$v['vcpus']).'</td><td>'.htmlspecialchars((string)$v['memory_mb']).'</td><td>'.htmlspecialchars($v['ip_address']).'</td></tr>';
        }
        $html = '<div class="card"><h2>VMs</h2>
        <table class="table"><thead><tr><th>UUID</th><th>Name</th><th>Type</th><th>vCPU</th><th>RAM(MB)</th><th>IP</th></tr></thead><tbody>'.$rows.'</tbody></table>
        </div>
        <div class="card"><h3>Create Instance</h3>
        <form method="post" action="/admin/vms">
            <label>Node</label><select name="node_id" required>'+ $nodeOptions +'</select>
            <label>Type</label><select name="type"><option value="kvm">KVM</option><option value="lxc">LXC</option></select>
            <input name="name" placeholder="vm-name" required>
            <input name="vcpus" type="number" placeholder="2" value="2" required>
            <input name="memory_mb" type="number" placeholder="2048" value="2048" required>
            <input name="disk_gb" type="number" placeholder="20" value="20" required>
            <input name="ip_address" placeholder="192.0.2.10" required>
            <input name="bridge" placeholder="br0" value="br0" required>
            <input name="image_id" type="number" placeholder="1" value="1" required>
            <button type="submit">Create</button>
        </form></div>';
        View::render('VMs', $html);
    }
    public function store() {
        Auth::require();
        $uuid = UUID::v4();
        $d = [
            'uuid'=>$uuid,
            'node_id'=>(int)($_POST['node_id'] ?? 1),
            'name'=>$_POST['name'] ?? 'vm',
            'type'=>$_POST['type'] ?? 'kvm',
            'vcpus'=>(int)($_POST['vcpus'] ?? 2),
            'memory_mb'=>(int)($_POST['memory_mb'] ?? 2048),
            'disk_gb'=>(int)($_POST['disk_gb'] ?? 20),
            'image_id'=>(int)($_POST['image_id'] ?? 1),
            'bridge'=>$_POST['bridge'] ?? 'br0',
            'ip_address'=>$_POST['ip_address'] ?? ''
        ];
        VM::create($d);
        // enqueue job
        $type = $d['type'] === 'lxc' ? 'LXC_CREATE' : 'KVM_CREATE';
        Job::enqueue($d['node_id'], $type, $d);
        header('Location: /admin/vms');
    }
}
