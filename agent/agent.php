#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use VMForge\Core\Env;
use VMForge\Core\Shell;

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
        default: return [false, "Unknown job type {$type}"];
    }
}

function kvm_create(array $p, string $bridge): array {
    $uuid = $p['uuid'] ?? uniqid('vm-', true);
    $name = $p['name'] ?? "vm-$uuid";
    $vcpus = (int)($p['vcpus'] ?? 2);
    $mem = (int)($p['memory_mb'] ?? 2048);
    $disk = (int)($p['disk_gb'] ?? 20);
    $imgPath = "/var/lib/libvirt/images/{$name}.qcow2";
    [$c1, $o1, $e1] = Shell::run("qemu-img create -f qcow2 {$imgPath} {$disk}G");
    if ($c1 !== 0) return [false, $e1 ?: $o1];

    $bridgeName = $p['bridge'] ?? $bridge;
    $ip = $p['ip_address'] ?? '';
    $imageId = (int)($p['image_id'] ?? 1);
    // Minimal domain XML
    $xml = <<<XML
<domain type='kvm'>
  <name>{$name}</name>
  <memory unit='MiB'>{$mem}</memory>
  <vcpu placement='static'>{$vcpus}</vcpu>
  <os>
    <type arch='x86_64' machine='pc-q35-7.2'>hvm</type>
  </os>
  <devices>
    <disk type='file' device='disk'>
      <driver name='qemu' type='qcow2'/>
      <source file='{$imgPath}'/>
      <target dev='vda' bus='virtio'/>
    </disk>
    <interface type='bridge'>
      <source bridge='{$bridgeName}'/>
      <model type='virtio'/>
    </interface>
    <graphics type='vnc' port='-1' autoport='yes'/>
  </devices>
</domain>
XML;
    $tmp = sys_get_temp_dir() . "/vmforge-{$name}.xml";
    file_put_contents($tmp, $xml);
    [$c2, $o2, $e2] = Shell::run("virsh define {$tmp} && virsh start {$name}");
    if ($c2 !== 0) return [false, $e2 ?: $o2];
    return [true, "defined+started {$name}"];
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
