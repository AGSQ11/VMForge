#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use VMForge\Core\Env;
use VMForge\Core\Shell;
use VMForge\Services\ImageManager;
use VMForge\Services\CloudInit;

$controller = Env::get('AGENT_CONTROLLER_URL', 'http://localhost');
$token = Env::get('AGENT_NODE_TOKEN', 'changeme');
$bridge = Env::get('AGENT_BRIDGE', 'br0');
$poll = (int)Env::get('AGENT_POLL_INTERVAL', '5');

echo "VMForge Agent — an ENGINYRING project — starting...\n";

while (true) {
    $job = pollJob($controller, $token);
    if (!$job) { sleep($poll); continue; }
    $id = (int)$job['id'];
    $type = $job['type'];
    $payload = json_decode($job['payload'], true);

    [$status, $log] = executeJob($type, $payload, $bridge);
    ackJob($controller, $id, $status ? 'done' : 'failed', $log);
}

function pollJob(string $controller, string $token): ?array {
    $ch = curl_init("{$controller}/agent/poll");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>['token'=>$token]]);
    $out = curl_exec($ch);
    if ($out === false) return null;
    $data = json_decode($out, true);
    return $data['job'] ?? null;
}

function ackJob(string $controller, int $id, string $status, string $log): void {
    $ch = curl_init("{$controller}/agent/ack");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>['id'=>$id,'status'=>$status,'log'=>$log]]);
    curl_exec($ch); curl_close($ch);
}

function executeJob(string $type, array $p, string $bridge): array {
    switch ($type) {
        case 'KVM_CREATE': return kvm_create($p, $bridge);
        case 'LXC_CREATE': return lxc_create($p, $bridge);
        case 'KVM_CONSOLE_OPEN': return kvm_console_open($p, $bridge);
        case 'KVM_CONSOLE_CLOSE': return kvm_console_close($p, $bridge);
        default: return [false, "Unknown job type {$type}"];
    }
}

function kvm_create(array $p, string $bridge): array {
    $uuid  = $p['uuid'] ?? uniqid('vm-', true);
    $name  = $p['name'] ?? "vm-$uuid";
    $vcpus = (int)($p['vcpus'] ?? 2);
    $mem   = (int)($p['memory_mb'] ?? 2048);
    $disk  = (int)($p['disk_gb'] ?? 20);
    $imgId = (int)($p['image_id'] ?? 1);
    $br    = $p['bridge'] ?? $bridge;

    $im = new ImageManager();
    [$ok, $base] = $im->downloadIfMissing($imgId);
    if (!$ok) return [false, "image: ".$base];

    $overlay = "/var/lib/libvirt/images/{$name}.qcow2";
    [$c0,$o0,$e0] = Shell::run("qemu-img create -f qcow2 -b ".escapeshellarg($base)." -F qcow2 ".escapeshellarg($overlay));
    if ($c0 !== 0) return [false, $e0 ?: $o0];

    [$cg,$og,$eg] = Shell::run("qemu-img resize ".escapeshellarg($overlay)." ".escapeshellarg("{$disk}G"));
    if ($cg !== 0) return [false, $eg ?: $og];

    $seedDir = "/var/lib/libvirt/seed/{$name}";
    $net = null;
    if (!empty($p['ip_address'])) {
        $net = ['address'=>$p['ip_address'], 'prefix'=>$p['prefix'] ?? 24, 'gateway'=>$p['gateway'] ?? '', 'dns'=>$p['dns'] ?? ['1.1.1.1']];
    }
    [$cs,$co,$ce] = CloudInit::buildSeedISO($seedDir, $name, $name, $p['ci_user'] ?? 'admin', $p['ssh_key'] ?? null, $p['ci_password'] ?? null, $net);
    if ($cs !== 0) return [false, $ce ?: $co];

    $xml = <<<XML
<domain type='kvm'>
  <name>{$name}</name>
  <memory unit='MiB'>{$mem}</memory>
  <vcpu placement='static'>{$vcpus}</vcpu>
  <os><type arch='x86_64'>hvm</type></os>
  <devices>
    <disk type='file' device='disk'>
      <driver name='qemu' type='qcow2'/>
      <source file='{$overlay}'/>
      <target dev='vda' bus='virtio'/>
    </disk>
    <disk type='file' device='cdrom'>
      <driver name='qemu' type='raw'/>
      <source file='{$seedDir}/seed.iso'/>
      <target dev='sda' bus='sata'/><readonly/>
    </disk>
    <interface type='bridge'><source bridge='{$br}'/><model type='virtio'/></interface>
    <graphics type='vnc' port='-1' autoport='yes'/>
  </devices>
</domain>
XML;
    $tmp = sys_get_temp_dir() . "/vmforge-{$name}.xml";
    file_put_contents($tmp, $xml);
    [$c2, $o2, $e2] = Shell::run("virsh define {$tmp} && virsh start {$name}");
    if ($c2 !== 0) return [false, $e2 ?: $o2];
    return [true, "defined+started {$name} with cloud-init"];
}

function lxc_create(array $p, string $bridge): array {
    $name = $p['name'] ?? uniqid('ct-', true);
    $release = $p['release'] ?? 'bookworm';
    $arch = $p['arch'] ?? 'amd64';
    $bridgeName = $p['bridge'] ?? $bridge;
    [$c1,$o1,$e1] = Shell::run("lxc-create -n {$name} -t download -- --dist debian --release {$release} --arch {$arch}");
    if ($c1 !== 0) return [false, $e1 ?: $o1];
    $conf = "/var/lib/lxc/{$name}/config";
    $net = "\nlxc.net.0.type = veth\nlxc.net.0.link = {$bridgeName}\nlxc.net.0.flags = up\n";
    file_put_contents($conf, $net, FILE_APPEND);
    [$c2,$o2,$e2] = Shell::run("lxc-start -n {$name}");
    if ($c2 !== 0) return [false, $e2 ?: $o2];
    return [true, "created+started {$name}"];
}

// Console helpers
function kvm_console_open(array $p, string $bridge): array {
    $name = $p['name'] ?? null;
    if (!$name) return [false, 'missing vm name'];
    [$cd,$od,$ed] = Shell::run("virsh vncdisplay ".escapeshellarg($name));
    if ($cd !== 0) return [false, $ed ?: $od];
    $display = trim($od); // like :1
    if (!preg_match('/:(\d+)/', $display, $m)) return [false, 'bad vnc display'];
    $vnc = 5900 + (int)$m[1];
    $listen = (int)($p['listen_port'] ?? 6080);
    $cmd = "websockify --daemon --web /usr/share/novnc 0.0.0.0:{$listen} 127.0.0.1:{$vnc}";
    [$cw,$ow,$ew] = Shell::run($cmd);
    if ($cw !== 0) return [false, $ew ?: $ow];
    return [true, "port={$listen}"];
}

function kvm_console_close(array $p, string $bridge): array {
    $listen = (int)($p['listen_port'] ?? 0);
    if ($listen <= 0) return [false, 'missing listen_port'];
    [$ck,$ok,$ek] = Shell::run("fuser -k {$listen}/tcp || true");
    return [true, "closed={$listen}"];
}
