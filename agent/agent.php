#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use VMForge\Core\Env;
use VMForge\Core\Shell;
use VMForge\Services\ISOStore;

// Banner
echo "VMForge Agent — an ENGINYRING project — starting...\n";

$controller = Env::get('AGENT_CONTROLLER_URL', 'http://localhost');
$token      = Env::get('AGENT_NODE_TOKEN', 'changeme');
$bridge     = Env::get('AGENT_BRIDGE', 'br0');
$poll       = (int)Env::get('AGENT_POLL_INTERVAL', '5');

while (true) {
    $job = pollJob($controller, $token);
    if (!$job) { sleep($poll); continue; }
    $id = (int)$job['id'];
    $type = (string)($job['type'] ?? '');
    $payload = json_decode((string)($job['payload'] ?? '{}'), true) ?: [];
    [$ok, $log] = executeJob($type, $payload, $bridge);
    ackJob($controller, $id, $ok ? 'done' : 'failed', is_array($log) ? json_encode($log) : (string)$log);
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
        // Pack 29 additions:
        case 'BANDWIDTH_COLLECT':      return net_bw_collect($p, $bridge);
        case 'NET_EGRESS_CAP_SET':     return net_egress_cap_set($p, $bridge);
        case 'NET_EGRESS_CAP_CLEAR':   return net_egress_cap_clear($p, $bridge);
        default:                       return [false, "Unknown job type {$type}"];
    }
}

/* ========================= KVM helpers ========================= */

function kvm_start(array $p, string $bridge): array {
    $name = $p['name'] ?? null; if (!$name) return [false,'missing name'];
    [$c,$o,$e] = Shell::runf('virsh', ['start', $name]);
    return [$c===0, $e?:$o];
}

function kvm_stop(array $p, string $bridge): array {
    $name = $p['name'] ?? null; if (!$name) return [false,'missing name'];
    [$c,$o,$e] = Shell::runf('virsh', ['shutdown', $name]);
    if ($c!==0) return [false, $e?:$o];
    return [true, 'shutdown sent'];
}

function kvm_reboot(array $p, string $bridge): array {
    $name = $p['name'] ?? null; if (!$name) return [false,'missing name'];
    [$c,$o,$e] = Shell::runf('virsh', ['reboot', $name]);
    return [$c===0, $e?:$o];
}

function kvm_delete(array $p, string $bridge): array {
    $name = $p['name'] ?? null; if (!$name) return [false,'missing name'];
    [$c1,$o1,$e1] = Shell::runf('virsh', ['destroy', $name]);
    [$c2,$o2,$e2] = Shell::runf('virsh', ['undefine', $name]);
    $ok = ($c2===0);
    return [$ok, ($e1?:$o1) . ' ' . ($e2?:$o2)];
}

function kvm_reinstall(array $p, string $bridge): array {
    $name = $p['name'] ?? null; if (!$name) return [false,'missing vm name'];
    $isoId = (int)($p['iso_id'] ?? 0); if ($isoId < 1) return [false,'missing iso_id'];

    $path = ISOStore::ensureLocal($isoId);
    if (!$path || !is_file($path)) return [false, 'iso not available'];

    // Best-effort stop
    Shell::runf('virsh', ['shutdown', $name]);

    // Dump XML, inject cdrom + boot=cdrom, redefine, start
    [$cxml,$oxml,$exml] = Shell::runf('virsh', ['dumpxml', $name]);
    if ($cxml !== 0) return [false, $exml ?: $oxml];
    $tmp = sys_get_temp_dir()."/vmforge-{$name}-reinstall.xml";
    $xml = $oxml;

    if (!preg_match('~<os>~', $xml)) {
        $xml = preg_replace('~</domain>~', "<os><type arch='x86_64'>hvm</type><boot dev='cdrom'/></os></domain>", $xml, 1);
    } else {
        if (!preg_match("~<boot dev='cdrom'/>~", $xml)) {
            $xml = preg_replace('~<os>(.*?)</os>~s', '<os>$1<boot dev=\'cdrom\'/></os>', $xml, 1);
        }
    }
    if (preg_match('~<disk[^>]+device=\'cdrom\'~', $xml)) {
        $xml = preg_replace(
            '~<disk[^>]+device=\'cdrom\'[\s\S]*?</disk>~',
            "<disk type='file' device='cdrom'><driver name='qemu' type='raw'/><source file='{$path}'/><target dev='sda' bus='sata'/><readonly/></disk>",
            $xml,
            1
        );
    } else {
        $xml = preg_replace(
            '~</devices>~',
            "<disk type='file' device='cdrom'><driver name='qemu' type='raw'/><source file='{$path}'/><target dev='sda' bus='sata'/><readonly/></disk></devices>",
            $xml,
            1
        );
    }
    file_put_contents($tmp, $xml);
    [$cdef,$odef,$edef] = Shell::runf('virsh', ['define', $tmp]);
    if ($cdef !== 0) return [false, $edef ?: $odef];
    [$cs,$os,$es] = Shell::runf('virsh', ['start', $name]);
    if ($cs !== 0) return [false, $es ?: $os];
    return [true, "reinstall: ISO attached and VM started"];
}

/* ========================= LXC helpers ========================= */

function lxc_start(array $p, string $bridge): array {
    $name = $p['name'] ?? null; if (!$name) return [false,'missing name'];
    [$c,$o,$e] = Shell::runf('lxc-start', ['-n', $name, '-d']);
    return [$c===0, $e?:$o];
}
function lxc_stop(array $p, string $bridge): array {
    $name = $p['name'] ?? null; if (!$name) return [false,'missing name'];
    [$c,$o,$e] = Shell::runf('lxc-stop', ['-n', $name]);
    return [$c===0, $e?:$o];
}
function lxc_delete(array $p, string $bridge): array {
    $name = $p['name'] ?? null; if (!$name) return [false,'missing name'];
    [$c,$o,$e] = Shell::runf('lxc-destroy', ['-n', $name]);
    return [$c===0, $e?:$o];
}

/* ============== Stubs for unimplemented job types ============== */

function kvm_create(array $p, string $bridge): array { return [false, 'KVM_CREATE not implemented on this agent build']; }
function lxc_create(array $p, string $bridge): array { return [false, 'LXC_CREATE not implemented on this agent build']; }
function kvm_console_open(array $p, string $bridge): array { return [true, 'noop']; }
function kvm_console_close(array $p, string $bridge): array { return [true, 'noop']; }
function net_setup(array $p, string $bridge): array { return [true, 'noop']; }
function snapshot_create(array $p, string $bridge): array { return [false, 'SNAPSHOT_CREATE not implemented']; }
function backup_upload(array $p, string $bridge): array { return [false, 'BACKUP_UPLOAD handled by master']; }
function backup_restore(array $p, string $bridge): array { return [false, 'BACKUP_RESTORE handled by master']; }
function backup_restore_as_new(array $p, string $bridge): array { return [false, 'BACKUP_RESTORE_AS_NEW handled by master']; }
function disk_resize(array $p, string $bridge): array { return [false, 'DISK_RESIZE not implemented']; }

/* ===================== Antispoof / nft ========================= */

function net_antispoof(array $p, string $bridge): array {
    // Expect payload: { mac: "...", ipv4: "x.x.x.x/32", iface: "vnetX" }
    $mac  = $p['mac']  ?? null;
    $ipv4 = $p['ipv4'] ?? null;
    $iface= $p['iface']?? null;
    if (!$mac || !$ipv4 || !$iface) return [false, 'missing mac/ipv4/iface'];

    $rules = "table inet vmforge {\n"
           . " chain antispoof {\n"
           . "  type filter hook forward priority 0;\n"
           . " }\n"
           . "}\n"
           . "add rule inet vmforge antispoof iifname \"$iface\" ether saddr != $mac drop\n"
           . "add rule inet vmforge antispoof iifname \"$iface\" ip saddr != $ipv4 drop\n";

    $tmp = tempnam(sys_get_temp_dir(), 'nft-');
    file_put_contents($tmp, $rules);
    [$c,$o,$e] = Shell::runf('nft', ['-f', $tmp]);
    @unlink($tmp);
    return [$c===0, $e?:$o];
}

/* =================== Pack 29: Bandwidth ======================== */

function net_bw_collect(array $p, string $bridge): array {
    [$cl,$ol,$el] = Shell::runf('virsh', ['list', '--name']);
    if ($cl !== 0) return [false, 'virsh list failed: ' . ($el?:$ol)];
    $names = array_filter(array_map('trim', explode("\n", trim((string)$ol))));
    $entries = [];
    foreach ($names as $name) {
        $if = agent_find_tap_for_vm($name);
        if (!$if) continue;
        $rxp = "/sys/class/net/{$if}/statistics/rx_bytes";
        $txp = "/sys/class/net/{$if}/statistics/tx_bytes";
        $rx = is_readable($rxp) ? (int)trim((string)@file_get_contents($rxp)) : 0;
        $tx = is_readable($txp) ? (int)trim((string)@file_get_contents($txp)) : 0;
        $entries[] = ['name'=>$name, 'if'=>$if, 'rx_bytes'=>$rx, 'tx_bytes'=>$tx];
    }
    return [true, ['entries'=>$entries, 'ts'=>time()]];
}

function net_egress_cap_set(array $p, string $bridge): array {
    $name = $p['name'] ?? null;
    $mbps = isset($p['mbps']) ? (int)$p['mbps'] : 0;
    if (!$name || $mbps < 1) return [false, 'missing name or mbps'];
    $if = agent_find_tap_for_vm($name);
    if (!$if) return [false, 'tap not found'];

    // Best-effort cleanup
    Shell::runf('tc', ['qdisc', 'del', 'dev', $if, 'root']);

    $burst_kb = max(32, $mbps * 16); // rough: 16 KB per Mbps
    $rate = $mbps . 'mbit';
    $burst = (string)$burst_kb . 'kb';
    $lat = '50ms';

    [$c,$o,$e] = Shell::runf('tc', ['qdisc', 'add', 'dev', $if, 'root', 'tbf',
        'rate', $rate, 'burst', $burst, 'latency', $lat]);
    if ($c !== 0) return [false, $e ?: $o];
    return [true, "egress cap set on {$if} to {$mbps} Mbps"];
}

function net_egress_cap_clear(array $p, string $bridge): array {
    $name = $p['name'] ?? null;
    if (!$name) return [false, 'missing name'];
    $if = agent_find_tap_for_vm($name);
    if (!$if) return [false, 'tap not found'];
    [$c,$o,$e] = Shell::runf('tc', ['qdisc', 'del', 'dev', $if, 'root']);
    if ($c!==0 && strpos((string)$e.(string)$o,'No such file or directory')===false) {
        return [false, $e?:$o];
    }
    return [true, "egress cap cleared on {$if}"];
}

function agent_find_tap_for_vm(string $name): ?string {
    [$c,$o,$e] = Shell::runf('virsh', ['domiflist', $name]);
    if ($c !== 0) return null;
    $lines = preg_split('/\r?\n/', trim((string)$o));
    foreach ($lines as $ln) {
        $cols = preg_split('/\s+/', trim($ln));
        foreach ($cols as $tok) {
            if (preg_match('/^(vnet|tap)\d+$/', $tok)) return $tok;
        }
    }
    return null;
}
