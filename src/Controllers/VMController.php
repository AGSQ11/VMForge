<?php
namespace VMForge\Controllers;
use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\UUID;
use VMForge\Core\DB;
use VMForge\Core\Policy;
use VMForge\Core\Security;
use VMForge\Models\VM;
use VMForge\Models\Node;
use VMForge\Models\Job;
use VMForge\Services\IPAM;
use VMForge\Services\Storage;
use VMForge\Models\Image;
use PDO;

class VMController {
    public function index() {
        Auth::require();
        $pdo = DB::pdo();
        $pid = Policy::currentProjectId();
        if ($pid) {
            $st = $pdo->prepare('SELECT * FROM vm_instances WHERE project_id=? ORDER BY id DESC');
            $st->execute([$pid]);
            $vms = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $vms = VM::all();
        }
        $nodes = Node::all();
        $images = Image::all();
        $pools = Storage::all();
        $nodeOptions = '';
        foreach ($nodes as $n) { $nodeOptions .= '<option value="'.$n['id'].'">'.htmlspecialchars($n['name']).'</option>'; }
        $imageOptions = '';
        foreach ($images as $img) { $imageOptions .= '<option value="'.$img['id'].'">['.htmlspecialchars($img['type']).'] '.htmlspecialchars($img['name']).'</option>'; }
        $poolOptions = '<option value="">(default qcow2)</option>';
        foreach ($pools as $p) { $poolOptions .= '<option value="'.$p['id'].'">'.htmlspecialchars($p['name']).' ('.htmlspecialchars($p['driver']).')</option>'; }
        $rows = '';
        foreach ($vms as $v) {
            $console = $v['type']==='kvm' ? '<a href="/console/open?uuid='.htmlspecialchars($v['uuid']).'">Open Console</a>' : '-';
            $rows .= '<tr><td>'.htmlspecialchars($v['uuid']).'</td><td><a href="/admin/vm?uuid='.htmlspecialchars($v['uuid']).'">'.htmlspecialchars($v['name']).'</a></td><td>'.htmlspecialchars($v['type']).'</td><td>'.htmlspecialchars((string)$v['vcpus']).'</td><td>'.htmlspecialchars((string)$v['memory_mb']).'</td><td>'.htmlspecialchars($v['ip_address']).'</td><td>'.htmlspecialchars((string)$v['project_id']).'</td><td>'.$console.'</td></tr>';
        }
        $csrf = Security::csrfToken();
        $html = '<div class="card"><h2>VMs</h2>
        <table class="table"><thead><tr><th>UUID</th><th>Name</th><th>Type</th><th>vCPU</th><th>RAM(MB)</th><th>IP</th><th>Project</th><th>Console</th></tr></thead><tbody>'.$rows.'</tbody></table>
        </div>
        <div class="card"><h3>Create Instance</h3>
        <form method="post" action="/admin/vms">
            <input type="hidden" name="csrf" value="'.$csrf.'">
            <label>Node</label><select name="node_id" required>'+ $nodeOptions +'</select>
            <label>Type</label><select name="type"><option value="kvm">KVM</option><option value="lxc">LXC</option></select>
            <input name="name" placeholder="vm-name" required>
            <input name="vcpus" type="number" placeholder="2" value="2" required>
            <input name="memory_mb" type="number" placeholder="2048" value="2048" required>
            <input name="disk_gb" type="number" placeholder="20" value="20" required>
            <label>Image</label><select name="image_id" required>'+ $imageOptions +'</select>
            <label>Storage Pool</label><select name="storage_pool_id">'+ $poolOptions +'</select>
            <input name="ip_address" placeholder="192.0.2.10">
            <input name="bridge" placeholder="br0" value="br0" required>
            <input name="vlan_tag" type="number" placeholder="(optional VLAN tag)">
            <button type="submit">Create</button>
        </form></div>';
        View::render('VMs', $html);
    }
    private function macFromUuid(string $uuid): string {
        $hex = preg_replace('/[^a-f0-9]/i','', $uuid);
        $h = substr(hash('md5', $hex), 0, 10);
        $pairs = str_split($h, 2);
        $mac = ['02', $pairs[0], $pairs[1], $pairs[2], $pairs[3], $pairs[4]];
        return implode(':', $mac);
    }
    public function store() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $uuid = UUID::v4();
        $pid = Policy::requireProjectSelected();
        $user = Auth::user();
        $pdo = DB::pdo();
        $q = $pdo->prepare('SELECT max_vms, max_vcpus, max_ram_mb, max_disk_gb FROM quotas WHERE project_id=?');
        $q->execute([$pid]);
        $quota = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($quota) {
            $agg = $pdo->prepare('SELECT COUNT(*) as vms, COALESCE(SUM(vcpus),0) vcpus, COALESCE(SUM(memory_mb),0) ram, COALESCE(SUM(disk_gb),0) disk FROM vm_instances WHERE project_id=?');
            $agg->execute([$pid]);
            $cur = $agg->fetch(PDO::FETCH_ASSOC);
            if (!empty($quota['max_vms']) && ((int)$cur['vms'] + 1) > (int)$quota['max_vms']) die('quota exceeded: max_vms');
            if (!empty($quota['max_vcpus']) && ((int)$cur['vcpus'] + (int)$_POST['vcpus']) > (int)$quota['max_vcpus']) die('quota exceeded: max_vcpus');
            if (!empty($quota['max_ram_mb']) && ((int)$cur['ram'] + (int)$_POST['memory_mb']) > (int)$quota['max_ram_mb']) die('quota exceeded: max_ram_mb');
            if (!empty($quota['max_disk_gb']) && ((int)$cur['disk'] + (int)$_POST['disk_gb']) > (int)$quota['max_disk_gb']) die('quota exceeded: max_disk_gb');
        }
        $ip = $_POST['ip_address'] ?? '';
        if ($ip === '') {
            $poolId = (int)($pdo->query("SELECT id FROM ip_pools ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
            if ($poolId) { $ipTry = \VMForge\Services\IPAM::nextFree($poolId); if ($ipTry) $ip = $ipTry; }
        }
        $poolId = $_POST['storage_pool_id'] !== '' ? (int)$_POST['storage_pool_id'] : null;
        $storageType = 'qcow2';
        if ($poolId) {
            $st = $pdo->prepare('SELECT driver FROM storage_pools WHERE id=? LIMIT 1');
            $st->execute([$poolId]); $driver = (string)$st->fetchColumn();
            if ($driver) $storageType = $driver;
        }
        $d = [
            'uuid'=>$uuid,'project_id'=>$pid,'node_id'=>(int)$_POST['node_id'],'name'=>$_POST['name'],
            'type'=>$_POST['type'] ?? 'kvm','vcpus'=>(int)$_POST['vcpus'],'memory_mb'=>(int)$_POST['memory_mb'],
            'disk_gb'=>(int)$_POST['disk_gb'],'image_id'=>(int)$_POST['image_id'],
            'bridge'=>$_POST['bridge'] ?? 'br0','ip_address'=>$ip,
            'storage_type'=>$storageType,'storage_pool_id'=>$poolId,'vlan_tag'=> $_POST['vlan_tag'] !== '' ? (int)$_POST['vlan_tag'] : null
        ];
        $d['mac_address'] = $this->macFromUuid($uuid);
        VM::create($d);
        $type = $d['type'] === 'lxc' ? 'LXC_CREATE' : 'KVM_CREATE';
        Job::enqueue($d['node_id'], $type, $d);
        if ($d['type'] === 'kvm') {
            Job::enqueue($d['node_id'], 'NET_ANTISPOOF', ['name'=>$d['name'],'ip4'=>$d['ip_address'] ?? null,'mac'=>$d['mac_address']]);
        }
        header('Location: /admin/vms');
    }
}
