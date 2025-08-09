#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use VMForge\Core\Env;
use VMForge\Core\Shell;
use VMForge\Core\DB;
use VMForge\Services\ImageManager; // reserved for future use
use VMForge\Services\CloudInit;    // reserved for future use
use VMForge\Services\ISOStore;     // must exist from ISO pack

// -----------------------------------------------------------------------------
// Agent bootstrap
// -----------------------------------------------------------------------------
$controller = Env::get('AGENT_CONTROLLER_URL', 'http://localhost');
$token      = Env::get('AGENT_NODE_TOKEN', 'changeme');
$bridge     = Env::get('AGENT_BRIDGE', 'br0');
$poll       = (int)Env::get('AGENT_POLL_INTERVAL', '5');

echo "VMForge Agent — an ENGINYRING project — starting...\n";

while (true) {
    $job = pollJob($controller, $token);
    if (!$job) { sleep($poll); continue; }
    $id = (int)$job['id'];
    $type = (string)$job['type'];
    $payload = json_decode((string)$job['payload'], true) ?: [];
    [$ok, $log] = executeJob($type, $payload, $bridge);
    ackJob($controller, $id, $ok ? 'done' : 'failed', is_array($log) ? json_encode($log) : (string)$log);
}

function pollJob(string $controller, string $token): ?array {
    $ch = curl_init($controller . '/agent/poll');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['token' => $token],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $out = curl_exec($ch);
    if ($out === false) return null;
    $data = json_decode($out, true);
    return $data['job'] ?? null;
}

function ackJob(string $controller, int $id, string $status, string $log): void {
    $ch = curl_init($controller . '/agent/ack');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['id' => $id, 'status' => $status, 'log' => $log],
        CURLOPT_TIMEOUT        => 20,
    ]);
    curl_exec($ch); curl_close($ch);
}

function executeJob(string $type, array $p, string $bridge): array {
    switch ($type) {
        // KVM lifecycle
        case 'KVM_CREATE':             return kvm_create($p, $bridge);
        case 'KVM_START':              return kvm_start($p, $bridge);
        case 'KVM_STOP':               return kvm_stop($p, $bridge);
        case 'KVM_REBOOT':             return kvm_reboot($p, $bridge);
        case 'KVM_DELETE':             return kvm_delete($p, $bridge);
        case 'KVM_REINSTALL':          return kvm_reinstall($p, $bridge);
        case 'KVM_CONSOLE_OPEN':       return [true, 'noop'];
        case 'KVM_CONSOLE_CLOSE':      return [true, 'noop'];

        // LXC minimal
        case 'LXC_CREATE':             return lxc_create($p, $bridge);
        case 'LXC_START':              return lxc_start($p, $bridge);
        case 'LXC_STOP':               return lxc_stop($p, $bridge);
        case 'LXC_DELETE':             return lxc_delete($p, $bridge);

        // Network
        case 'NET_SETUP':              return net_setup($p, $bridge);
        case 'NET_ANTISPOOF':          return [true, 'noop'];
        case 'NET6_RA_SETUP':          return net6_ra_setup($p, $bridge);
        case 'FW_SYNC':                return fw_sync($p, $bridge);

        // Storage / disk
        case 'DISK_RESIZE':            return disk_resize($p, $bridge);

        // Backups / snapshots (stubs until controller paths are finalized)
        case 'SNAPSHOT_CREATE':        return [true, 'snapshot stub'];
        case 'BACKUP_UPLOAD':          return [true, 'backup upload stub'];
        case 'BACKUP_RESTORE':         return [true, 'backup restore stub'];
        case 'BACKUP_RESTORE_AS_NEW':  return [true, 'backup restore-as-new stub'];

        // ZFS (stubs if you didn’t add ZFS pack)
        case 'ZFS_BACKUP':             return [true, 'zfs backup stub'];
        case 'ZFS_PRUNE':              return [true, 'zfs prune stub'];
        case 'ZFS_RESTORE':            return [true, 'zfs restore stub'];
        case 'ZFS_RESTORE_AS_NEW':     return [true, 'zfs restore-as-new stub'];

        default:                       return [false, 'Unknown job type ' . $type];
    }
}

// -----------------------------------------------------------------------------
// KVM — minimal but safe implementations
// -----------------------------------------------------------------------------
function kvm_create(array $p, string $defaultBridge): array {
    $name  = trim((string)($p['name'] ?? ''));
    $vcpus = max(1, (int)($p['vcpus'] ?? 1));
    $ramMb = max(256, (int)($p['memory_mb'] ?? 1024));
    $diskG = max(1, (int)($p['disk_gb'] ?? 10));
    $br    = trim((string)($p['bridge'] ?? $defaultBridge));
    if ($name === '') return [false, 'missing name'];

    $imgDir = '/var/lib/libvirt/images';
    @mkdir($imgDir, 0755, true);
    $disk = $imgDir . '/' . preg_replace('~[^a-zA-Z0-9._-]+~', '_', $name) . '.qcow2';

    if (!file_exists($disk)) {
        [$cq,$oq,$eq] = Shell::runf('qemu-img', ['create', '-f', 'qcow2', $disk, (string)$diskG . 'G']);
        if ($cq !== 0) return [false, $eq ?: $oq];
    }

    $uuid = isset($p['uuid']) ? preg_replace('~[^a-f0-9-]~i', '', (string)$p['uuid']) : null;
    $mac  = (string)($p['mac_address'] ?? '');
    if (!preg_match('~^([0-9a-f]{2}:){5}[0-9a-f]{2}$~i', $mac)) {
        $h = substr(hash('sha1', $name), 0, 12);
        $mac = '02:' . substr($h,0,2).':' . substr($h,2,2).':' . substr($h,4,2).':' . substr($h,6,2).':' . substr($h,8,2);
    }

    $xml = "<domain type='kvm'>\n"
         . "  <name>" . htmlspecialchars($name, ENT_QUOTES) . "</name>\n"
         . ($uuid ? ("  <uuid>" . htmlspecialchars($uuid, ENT_QUOTES) . "</uuid>\n") : '')
         . "  <memory unit='MiB'>{$ramMb}</memory>\n"
         . "  <vcpu placement='static'>{$vcpus}</vcpu>\n"
         . "  <os><type arch='x86_64' machine='pc'>hvm</type></os>\n"
         . "  <features><acpi/><apic/></features>\n"
         . "  <clock offset='utc'/>\n"
         . "  <devices>\n"
         . "    <emulator>/usr/bin/qemu-system-x86_64</emulator>\n"
         . "    <disk type='file' device='disk'><driver name='qemu' type='qcow2'/><source file='" . htmlspecialchars($disk, ENT_QUOTES) . "'/><target dev='vda' bus='virtio'/></disk>\n"
         . "    <interface type='bridge'><source bridge='" . htmlspecialchars($br, ENT_QUOTES) . "'/><model type='virtio'/><mac address='" . htmlspecialchars($mac, ENT_QUOTES) . "'/></interface>\n"
         . "    <graphics type='vnc' autoport='yes' listen='127.0.0.1'/>\n"
         . "    <console type='pty'/>\n"
         . "  </devices>\n"
         . "</domain>\n";

    $tmp = sys_get_temp_dir() . '/vmforge-' . preg_replace('~[^a-zA-Z0-9._-]+~', '_', $name) . '.xml';
    file_put_contents($tmp, $xml);

    [$cdef,$odef,$edef] = Shell::runf('virsh', ['define', $tmp]);
    if ($cdef !== 0) return [false, $edef ?: $odef];

    [$cs,$os,$es] = Shell::runf('virsh', ['start', $name]);
    if ($cs !== 0) return [false, $es ?: $os];

    return [true, 'created'];
}

function kvm_start(array $p, string $bridge): array {
    $name = trim((string)($p['name'] ?? ''));
    if ($name === '') return [false, 'missing name'];
    [$c,$o,$e] = Shell::runf('virsh', ['start', $name]);
    return [$c === 0, $e ?: $o];
}
function kvm_stop(array $p, string $bridge): array {
    $name = trim((string)($p['name'] ?? ''));
    if ($name === '') return [false, 'missing name'];
    [$c,$o,$e] = Shell::runf('virsh', ['shutdown', $name]);
    if ($c !== 0) { [$c2,$o2,$e2] = Shell::runf('virsh', ['destroy', $name]); return [$c2===0, $e2?:$o2?:$e?:$o]; }
    return [true, $e ?: $o];
}
function kvm_reboot(array $p, string $bridge): array {
    $name = trim((string)($p['name'] ?? ''));
    if ($name === '') return [false, 'missing name'];
    [$c,$o,$e] = Shell::runf('virsh', ['reboot', $name]);
    return [$c === 0, $e ?: $o];
}
function kvm_delete(array $p, string $bridge): array {
    $name = trim((string)($p['name'] ?? ''));
    if ($name === '') return [false, 'missing name'];
    Shell::runf('virsh', ['destroy', $name]);
    [$c,$o,$e] = Shell::runf('virsh', ['undefine', $name, '--nvram', '--managed-save']);
    return [$c === 0, $e ?: $o];
}

function kvm_reinstall(array $p, string $bridge): array {
    $name = trim((string)($p['name'] ?? ''));
    $isoId = (int)($p['iso_id'] ?? 0);
    if ($name === '' || $isoId < 1) return [false, 'missing name/iso_id'];

    $path = ISOStore::ensureLocal($isoId);
    if (!$path || !file_exists($path)) return [false, 'iso not available'];

    Shell::runf('virsh', ['shutdown', $name]);

    [$cxml,$oxml,$exml] = Shell::runf('virsh', ['dumpxml', $name]);
    if ($cxml !== 0) return [false, $exml ?: $oxml];
    $xml = $oxml;

    if (!preg_match('~<os>~', $xml)) {
        $xml = preg_replace('~</domain>~', "<os><type arch='x86_64'>hvm</type><boot dev='cdrom'/></os></domain>", $xml, 1);
    } else {
        if (!preg_match("~<boot dev='cdrom'/>~", $xml)) {
            $xml = preg_replace('~<os>(.*?)</os>~s', "<os>$1<boot dev='cdrom'/></os>", $xml, 1);
        }
    }

    if (preg_match("~<disk[^>]+device='cdrom'~", $xml)) {
        $xml = preg_replace("~<disk[^>]+device='cdrom'[\s\S]*?</disk>~", "<disk type='file' device='cdrom'><driver name='qemu' type='raw'/><source file='" . htmlspecialchars($path, ENT_QUOTES) . "'/><target dev='sda' bus='sata'/><readonly/></disk>", $xml, 1);
    } else {
        $xml = preg_replace('~</devices>~', "<disk type='file' device='cdrom'><driver name='qemu' type='raw'/><source file='" . htmlspecialchars($path, ENT_QUOTES) . "'/><target dev='sda' bus='sata'/><readonly/></disk></devices>", $xml, 1);
    }

    $tmp = sys_get_temp_dir() . '/vmforge-' . preg_replace('~[^a-zA-Z0-9._-]+~', '_', $name) . '-reinstall.xml';
    file_put_contents($tmp, $xml);
    [$cdef,$odef,$edef] = Shell::runf('virsh', ['define', $tmp]);
    if ($cdef !== 0) return [false, $edef ?: $odef];
    [$cs,$os,$es] = Shell::runf('virsh', ['start', $name]);
    if ($cs !== 0) return [false, $es ?: $os];
    return [true, 'reinstall: ISO attached and VM started'];
}

// -----------------------------------------------------------------------------
// LXC — thin wrappers
// -----------------------------------------------------------------------------
function lxc_create(array $p, string $bridge): array {
    $name = trim((string)($p['name'] ?? ''));
    if ($name === '') return [false, 'missing name'];
    [$c,$o,$e] = Shell::runf('lxc-create', ['-n', $name, '-t', 'download', '--', '--dist', 'debian', '--release', 'bookworm', '--arch', 'amd64']);
    return [$c===0, $e?:$o];
}
function lxc_start(array $p, string $bridge): array {
    $name = trim((string)($p['name'] ?? ''));
    if ($name === '') return [false, 'missing name'];
    [$c,$o,$e] = Shell::runf('lxc-start', ['-n', $name, '-d']);
    return [$c===0, $e?:$o];
}
function lxc_stop(array $p, string $bridge): array {
    $name = trim((string)($p['name'] ?? ''));
    if ($name === '') return [false, 'missing name'];
    [$c,$o,$e] = Shell::runf('lxc-stop', ['-n', $name]);
    return [$c===0, $e?:$o];
}
function lxc_delete(array $p, string $bridge): array {
    $name = trim((string)($p['name'] ?? ''));
    if ($name === '') return [false, 'missing name'];
    [$c,$o,$e] = Shell::runf('lxc-destroy', ['-n', $name]);
    return [$c===0, $e?:$o];
}

// -----------------------------------------------------------------------------
// Networking helpers
// -----------------------------------------------------------------------------
function net_setup(array $p, string $defaultBridge): array {
    $br = trim((string)($p['bridge'] ?? $defaultBridge));
    if ($br === '') return [false, 'missing bridge'];
    // ensure bridge exists and is up
    Shell::runf('ip', ['link', 'add', $br, 'type', 'bridge']);
    Shell::runf('ip', ['link', 'set', $br, 'up']);
    return [true, 'bridge ensured'];
}

function net6_ra_setup(array $p, string $defaultBridge): array {
    $br = trim((string)($p['bridge'] ?? $defaultBridge));
    $prefix = trim((string)($p['prefix'] ?? ''));
    $gw = trim((string)($p['gateway'] ?? ''));
    $dns = trim((string)($p['dns'] ?? ''));
    if ($br === '' || $prefix === '' || $gw === '') return [false, 'missing bridge/prefix/gateway'];
    if (!preg_match('~^[0-9a-f:]+/64$~i', $prefix)) return [false, 'only /64 supported'];

    // enable forwarding
    Shell::runf('sysctl', ['-w', 'net.ipv6.conf.all.forwarding=1']);

    // if gw/64 not present on bridge, add it (parse output; no pipes)
    [$ci,$oi,$ei] = Shell::runf('ip', ['-6', 'addr', 'show', 'dev', $br]);
    if (strpos($oi, $gw . '/64') === false) {
        Shell::runf('ip', ['-6', 'addr', 'add', $gw . '/64', 'dev', $br]);
    }

    // radvd snippet
    $confDir = '/etc/radvd.d';
    @mkdir($confDir, 0755, true);
    $conf = "interface {$br} {\n  AdvSendAdvert on;\n  prefix {$prefix} {\n    AdvOnLink on;\n    AdvAutonomous on;\n  };\n";
    if ($dns !== '') { $conf .= "  RDNSS {$dns} {};\n"; }
    $conf .= "}\n";
    file_put_contents("{$confDir}/vmforge-{$br}.conf", $conf);

    // ensure /etc/radvd.conf includes the directory (no pipes; just append if missing)
    $main = '/etc/radvd.conf';
    $have = file_exists($main) ? (strpos((string)@file_get_contents($main), 'include /etc/radvd.d/*.conf;') !== false) : false;
    if (!$have) {
        file_put_contents($main, "include /etc/radvd.d/*.conf;\n", FILE_APPEND);
    }

    [$c,$o,$e] = Shell::runf('systemctl', ['restart', 'radvd']);
    if ($c !== 0) return [false, 'radvd restart failed; install and enable radvd'];
    return [true, "IPv6 RA configured on {$br} for {$prefix}"];
}

// -----------------------------------------------------------------------------
// Firewall (nftables) — no shell metacharacters; use nft JSON + scripts
// -----------------------------------------------------------------------------
function fw_sync(array $p, string $defaultBridge): array {
    $uuid = (string)($p['uuid'] ?? '');
    $name = (string)($p['name'] ?? '');
    if ($uuid === '' || $name === '') return [false,'missing uuid/name'];

    // Determine VM tap interface via virsh
    [$c,$out,$e] = Shell::runf('virsh', ['domiflist', $name]);
    if ($c !== 0 || trim($out) === '') return [false, 'cannot get interfaces for '.$name];
    $iface = null;
    foreach (explode("\n", $out) as $line) {
        if (preg_match('~^\s*(vnet\d+|tap\d+)\s+~', $line, $m)) { $iface = $m[1]; break; }
    }
    if (!$iface) $iface = 'vnet0';

    // DB: read mode + rules
    $pdo = DB::pdo();
    $st = $pdo->prepare('SELECT firewall_mode FROM vm_instances WHERE uuid=? LIMIT 1');
    $st->execute([$uuid]);
    $mode = (string)($st->fetchColumn() ?: 'disabled');

    $q = $pdo->prepare('SELECT protocol, source_cidr, dest_ports, action, priority FROM firewall_rules WHERE vm_uuid=? AND enabled=1 ORDER BY priority ASC, id ASC');
    $q->execute([$uuid]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);

    // Ensure table & forward chain exist (without metacharacters). When we need braces/semicolons, we feed a file to nft -f.
    if (!nft_table_exists('vmforge')) {
        nft_apply(["add table inet vmforge"]);
    }
    if (!nft_chain_exists('vmforge', 'vmforge_forward')) {
        nft_apply(["add chain inet vmforge vmforge_forward { type filter hook forward priority 0; policy accept; }"]);
    }

    $chain = 'vm-' . $iface;
    if (!nft_chain_exists('vmforge', $chain)) {
        nft_apply(["add chain inet vmforge {$chain} { type filter; }"]);
    }

    // Ensure single jump from forward->per-if chain
    if (!nft_forward_has_jump($iface, $chain)) {
        nft_apply(["add rule inet vmforge vmforge_forward iifname \"{$iface}\" jump {$chain}"]);
    }

    // Flush per-if chain then populate rules
    Shell::runf('nft', ['flush', 'chain', 'inet', 'vmforge', $chain]);

    if ($mode === 'disabled') {
        nft_apply(["add rule inet vmforge {$chain} return"]);
        return [true, 'firewall disabled for '.$name];
    }

    $script = [];
    foreach ($rows as $r) {
        $proto = (string)$r['protocol'];
        $src   = trim((string)$r['source_cidr']);
        $ports = trim((string)$r['dest_ports']);
        $act   = ($r['action'] === 'deny') ? 'drop' : 'accept';

        if (!in_array($proto, ['tcp','udp','icmp','any'], true)) continue;
        if ($src !== '' && strtolower($src) !== 'any') {
            $valid4 = (bool)preg_match('~^\d{1,3}(?:\.\d{1,3}){3}/\d{1,2}$~', $src);
            $valid6 = (bool)preg_match('~^[0-9a-f:]+/\d{1,3}$~i', $src);
            if (!$valid4 && !$valid6) continue;
        }
        if ($ports !== '' && strtolower($ports) !== 'any') {
            if (!preg_match('~^\d{1,5}(-\d{1,5})?(,\d{1,5}(-\d{1,5})?)*$~', $ports)) continue;
        }

        $conds = [];
        if     ($proto === 'tcp')  { $conds[] = 'meta l4proto tcp'; }
        elseif ($proto === 'udp')  { $conds[] = 'meta l4proto udp'; }
        elseif ($proto === 'icmp') { $conds[] = '(meta l4proto icmp || meta l4proto icmpv6)'; }

        if ($src !== '' && strtolower($src) !== 'any') {
            if (strpos($src, ':') !== false) { $conds[] = 'ip6 saddr ' . $src; }
            else { $conds[] = 'ip saddr ' . $src; }
        }
        if ($ports !== '' && strtolower($ports) !== 'any') {
            if (strpos($ports, ',') !== false || strpos($ports, '-') !== false) {
                $conds[] = '(tcp dport { ' . $ports . ' } || udp dport { ' . $ports . ' })';
            } else {
                $conds[] = '(tcp dport ' . $ports . ' || udp dport ' . $ports . ')';
            }
        }
        $script[] = 'add rule inet vmforge ' . $chain . ' ' . implode(' ', $conds) . ' ' . $act;
    }

    if ($mode === 'allowlist') {
        $script[] = 'add rule inet vmforge ' . $chain . ' counter drop';
    } else {
        $script[] = 'add rule inet vmforge ' . $chain . ' return';
    }

    if ($script) nft_apply($script);
    return [true, "fw synced for {$name} ({$iface}) with mode {$mode}"];
}

// nft helpers ---------------------------------------------------------------
function nft_table_exists(string $table): bool {
    [$c,$o,$e] = Shell::runf('nft', ['list', 'table', 'inet', $table]);
    return $c === 0;
}
function nft_chain_exists(string $table, string $chain): bool {
    [$c,$o,$e] = Shell::runf('nft', ['list', 'chain', 'inet', $table, $chain]);
    return $c === 0;
}
function nft_forward_has_jump(string $iface, string $chain): bool {
    [$c,$o,$e] = Shell::runf('nft', ['-j', 'list', 'chain', 'inet', 'vmforge', 'vmforge_forward']);
    if ($c !== 0) return false;
    $j = json_decode($o, true);
    if (!is_array($j)) return false;
    $rules = $j['nftables'] ?? [];
    foreach ($rules as $entry) {
        if (!isset($entry['rule'])) continue;
        $r = $entry['rule'];
        $expr = $r['expr'] ?? [];
        $hasIif = false; $hasJump = false;
        foreach ($expr as $ex) {
            if (isset($ex['match']['left']['payload']) && ($ex['match']['left']['payload']['field'] ?? '') === 'name' && ($ex['match']['right'] ?? '') === $iface) {
                $hasIif = true; }
            if (isset($ex['jump']['target']) && $ex['jump']['target'] === $chain) { $hasJump = true; }
        }
        if ($hasIif && $hasJump) return true;
    }
    return false;
}
function nft_apply(array $lines): void {
    $tmp = tempnam(sys_get_temp_dir(), 'nft');
    file_put_contents($tmp, implode("\n", $lines) . "\n");
    Shell::runf('nft', ['-f', $tmp]);
    @unlink($tmp);
}

// -----------------------------------------------------------------------------
// Disk operations
// -----------------------------------------------------------------------------
function disk_resize(array $p, string $bridge): array {
    $name   = trim((string)($p['name'] ?? ''));
    $sizeGb = (int)($p['new_size_gb'] ?? 0);
    if ($name === '' || $sizeGb < 1) return [false, 'missing params'];
    $disk = '/var/lib/libvirt/images/' . preg_replace('~[^a-zA-Z0-9._-]+~', '_', $name) . '.qcow2';
    if (!file_exists($disk)) return [false, 'disk not found'];

    [$c1,$o1,$e1] = Shell::runf('qemu-img', ['resize', $disk, (string)$sizeGb . 'G']);
    if ($c1 !== 0) return [false, $e1 ?: $o1];
    [$c2,$o2,$e2] = Shell::runf('virsh', ['blockresize', $name, 'vda', (string)$sizeGb . 'G']);
    return [$c2 === 0, $e2 ?: $o2];
}
