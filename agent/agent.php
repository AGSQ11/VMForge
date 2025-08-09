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
        case 'NET_SETUP': return net_setup($p, $bridge);
        case 'SNAPSHOT_CREATE': return snapshot_create($p, $bridge);
        case 'BACKUP_UPLOAD': return backup_upload($p, $bridge);
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
    if (!empty($p['ip_address']) || !empty($p['ipv6_address'])) {
        $net = [
            'ipv4' => !empty($p['ip_address']) ? ['address'=>$p['ip_address'],'prefix'=>$p['prefix'] ?? 24,'gateway'=>$p['gateway'] ?? ''] : null,
            'ipv6' => !empty($p['ipv6_address']) ? ['address'=>$p['ipv6_address'],'prefix'=>$p['ipv6_prefix'] ?? 64,'gateway'=>$p['ipv6_gateway'] ?? ''] : null,
            'dns'  => $p['dns'] ?? ['1.1.1.1','8.8.8.8']
        ];
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

function kvm_console_open(array $p, string $bridge): array {
    $name = $p['name'] ?? null;
    if (!$name) return [false, 'missing vm name'];
    [$cd,$od,$ed] = Shell::run("virsh vncdisplay ".escapeshellarg($name));
    if ($cd !== 0) return [false, $ed ?: $od];
    $display = trim($od);
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

function net_setup(array $p, string $bridge): array {
    // mode: nat or routed; bridge name; outbound interface guessed
    $mode = $p['mode'] ?? 'nat';
    $br   = $p['bridge'] ?? 'br0';
    $wan  = $p['wan_iface'] ?? trim(shell_exec("ip route | awk '/default/ {print $5; exit}'") ?: 'eth0');
    Shell::run("sysctl -w net.ipv4.ip_forward=1");
    Shell::run("sysctl -w net.ipv6.conf.all.forwarding=1");
    if ($mode === 'nat') {
        // Simple nftables NAT rules
        $rules = f"""
table inet vmforge {{
  set v4ifaces {{ type ifname; elements = {{ {br} }} }}
  set v6ifaces {{ type ifname; elements = {{ {br} }} }}
  chain prerouting {{ type nat hook prerouting priority -100; }}
  chain postrouting {{ type nat hook postrouting priority 100; }}
  chain input {{ type filter hook input priority 0; }}
  chain forward {{ type filter hook forward priority 0; ct state established,related accept; iifname {{br}} oifname {wan} accept; iifname {wan} oifname {{br}} accept; }}
  chain output {{ type filter hook output priority 0; }}
  chain snat {{ }}
  chain dnat {{ }}
  chain nat_post {{ }}
}}
add rule inet vmforge postrouting oifname {wan} masquerade
""";
        $tmp = sys_get_temp_dir()."/vmforge-nft.rules";
        file_put_contents($tmp, $rules);
        [$c,$o,$e] = Shell::run("nft -f ".escapeshellarg($tmp));
        if ($c !== 0) return [false, $e ?: $o];
        return [true, "nft nat applied via {wan} for {br}"];
    } else {
        // Routed mode: no NAT, just allow forwarding between br and wan
        $rules = f"""
table inet vmforge {{
  chain forward {{ type filter hook forward priority 0; ct state established,related accept; iifname {br} oifname {wan} accept; iifname {wan} oifname {br} accept; }}
}}
""";
        $tmp = sys_get_temp_dir()."/vmforge-nft.rules";
        file_put_contents($tmp, $rules);
        [$c,$o,$e] = Shell::run("nft -f ".escapeshellarg($tmp));
        if ($c !== 0) return [false, $e ?: $o];
        return [true, "routed rules applied {wan}<->{br}"];
    }
}

function snapshot_create(array $p, string $bridge): array {
    $name = $p['name'] ?? null;
    if (!$name) return [false, 'missing vm name'];
    $snap = $p['snapshot'] ?? ('snap-' . date('Ymd-His'));
    $cmd = "virsh snapshot-create-as --domain ".escapeshellarg($name)." ".escapeshellarg($snap)." --disk-only --atomic --quiesce --no-metadata || virsh snapshot-create-as --domain ".escapeshellarg($name)." ".escapeshellarg($snap)." --disk-only --atomic --no-metadata";
    [$c,$o,$e] = Shell::run($cmd);
    if ($c !== 0) return [false, $e ?: $o];
    return [true, $snap];
}

function backup_upload(array $p, string $bridge): array {
    $name = $p['name'] ?? null;
    if (!$name) return [false, 'missing vm name'];
    $target = $p['target'] ?? 'local'; // local or s3
    $snap = $p['snapshot'] ?? null;
    if (!$snap) return [false, 'missing snapshot'];
    $disk = trim(shell_exec("virsh domblklist ".escapeshellarg($name)." | awk '/vda/ {print $2}'") ?: '');
    if (!$disk) return [false, 'cannot find disk'];
    $path = "/var/lib/vmforge/backups/{$name}-{$snap}.qcow2";
    @mkdir("/var/lib/vmforge/backups", 0755, true);
    [$c,$o,$e] = Shell::run("qemu-img convert -O qcow2 ".escapeshellarg($disk)." ".escapeshellarg($path));
    if ($c !== 0) return [false, $e ?: $o];
    if ($target === 's3') {
        $bucket = getenv('S3_BUCKET') ?: '';
        if (!$bucket) return [false, 'S3 not configured'];
        $key = "{$name}/{$snap}.qcow2";
        [$cs3,$os3,$es3] = Shell::run("aws s3 cp ".escapeshellarg($path)." ".escapeshellarg("s3://{$bucket}/{$key}"));
        if ($cs3 !== 0) return [false, $es3 ?: $os3];
        return [true, "s3://{$bucket}/{$key}"];
    }
    return [true, $path];
}
