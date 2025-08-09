#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use VMForge\Core\Env;
use VMForge\Core\Shell;
use VMForge\Services\ImageManager;
use VMForge\Services\CloudInit;
use VMForge\Services\ISOStore;

$controller = Env::get('AGENT_CONTROLLER_URL', 'http://localhost');
$token      = Env::get('AGENT_NODE_TOKEN', 'changeme');
$bridge     = Env::get('AGENT_BRIDGE', 'br0');
$poll       = (int)Env::get('AGENT_POLL_INTERVAL', '5');

echo "VMForge Agent — an ENGINYRING project — starting...\n";

while (true) {
    $job = pollJob($controller, $token);
    if (!$job) { sleep($poll); continue; }
    $id = (int)$job['id'];
    $type = $job['type'];
    $payload = json_decode($job['payload'], true);
    [$status, $log] = executeJob($type, $payload, $bridge);
    ackJob($controller, $id, $status ? 'done' : 'failed', is_array($log) ? json_encode($log) : (string)$log);
}

function pollJob(string $controller, string $token): ?array {
    $ch = curl_init("{$controller}/agent/poll");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>['token'=>$token],
        CURLOPT_TIMEOUT=>20,
    ]);
    $out = curl_exec($ch);
    if ($out === false) return null;
    $data = json_decode($out, true);
    return $data['job'] ?? null;
}

function ackJob(string $controller, int $id, string $status, string $log): void {
    $ch = curl_init("{$controller}/agent/ack");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>['id'=>$id,'status'=>$status,'log'=>$log],
        CURLOPT_TIMEOUT=>20,
    ]);
    curl_exec($ch); curl_close($ch);
}

function executeJob(string $type, array $p, string $bridge): array {
    switch ($type) {
        case 'KVM_CREATE':             return kvm_create($p, $bridge);
        case 'LXC_CREATE':             return lxc_create($p, $bridge);
        case 'KVM_CONSOLE_OPEN':       return kvm_console_open($p, $bridge);
        case 'KVM_CONSOLE_CLOSE':      return kvm_console_close($p, $bridge);
        case 'NET_SETUP':              return net_setup($p, $bridge);
        case 'SNAPSHOT_CREATE':        return snapshot_create($p, $bridge);
        case 'BACKUP_UPLOAD':          return backup_upload($p, $bridge);
        case 'BACKUP_RESTORE':         return backup_restore($p, $bridge);
        case 'BACKUP_RESTORE_AS_NEW':  return backup_restore_as_new($p, $bridge);
        case 'DISK_RESIZE':            return disk_resize($p, $bridge);
        case 'KVM_REINSTALL':          return kvm_reinstall($p, $bridge);
        case 'KVM_START':              return kvm_start($p, $bridge);
        case 'KVM_STOP':               return kvm_stop($p, $bridge);
        case 'KVM_REBOOT':             return kvm_reboot($p, $bridge);
        case 'KVM_DELETE':             return kvm_delete($p, $bridge);
        case 'LXC_START':              return lxc_start($p, $bridge);
        case 'LXC_STOP':               return lxc_stop($p, $bridge);
        case 'LXC_DELETE':             return lxc_delete($p, $bridge);
        case 'NET_ANTISPOOF':          return net_antispoof($p, $bridge);
        default:                       return [false, "Unknown job type {$type}"];
    }
}

// ... existing agent helper functions here (kvm_create, etc.) unchanged ...
// We only append the reinstall + ISO bits below. Keep disk_resize and others already present.

function kvm_reinstall(array $p, string $bridge): array {
    $name = $p['name'] ?? null; if (!$name) return [false,'missing vm name'];
    $isoId = (int)($p['iso_id'] ?? 0); if ($isoId < 1) return [false,'missing iso_id'];

    // Fetch/calc ISO path via controller DB (agent shares code and DB connection)
    $path = \VMForge\Services\ISOStore::ensureLocal($isoId);
    if (!$path || !file_exists($path)) return [false, 'iso not available'];

    // Stop VM, attach ISO as cdrom, set boot dev to cdrom first, start VM
    \VMForge\Core\Shell::run("virsh shutdown ".escapeshellarg($name)." || true");
    // ensure defined
    [$cxml,$oxml,$exml] = \VMForge\Core\Shell::run("virsh dumpxml ".escapeshellarg($name));
    if ($cxml !== 0) return [false, $exml ?: $oxml];
    $tmp = sys_get_temp_dir()."/vmforge-{$name}-reinstall.xml";
    // Add or replace cdrom + boot order
    $xml = $oxml;
    // naive: force boot cdrom by injecting <os><boot dev='cdrom'/></os> if missing
    if (!preg_match('~<os>~', $xml)) {
        $xml = preg_replace('~</domain>~', "<os><type arch='x86_64'>hvm</type><boot dev='cdrom'/></os></domain>", $xml, 1);
    } else {
        if (!preg_match("~<boot dev='cdrom'/>~", $xml)) {
            $xml = preg_replace('~<os>(.*?)</os>~s', '<os>$1<boot dev=\'cdrom\'/></os>', $xml, 1);
        }
    }
    // add/replace cdrom disk
    if (preg_match('~<disk[^>]+device=\'cdrom\'~', $xml)) {
        $xml = preg_replace('~<disk[^>]+device=\'cdrom\'[\s\S]*?</disk>~', "<disk type='file' device='cdrom'><driver name='qemu' type='raw'/><source file='{$path}'/><target dev='sda' bus='sata'/><readonly/></disk>", $xml, 1);
    } else {
        $xml = preg_replace('~</devices>~', "<disk type='file' device='cdrom'><driver name='qemu' type='raw'/><source file='{$path}'/><target dev='sda' bus='sata'/><readonly/></disk></devices>", $xml, 1);
    }
    file_put_contents($tmp, $xml);
    [$cdef,$odef,$edef] = \VMForge\Core\Shell::run("virsh define {$tmp}");
    if ($cdef !== 0) return [false, $edef ?: $odef];
    [$cs,$os,$es] = \VMForge\Core\Shell::run("virsh start ".escapeshellarg($name));
    if ($cs !== 0) return [false, $es ?: $os];
    return [true, "reinstall: ISO attached and VM started"];
}
