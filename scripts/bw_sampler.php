<?php
// scripts/bw_sampler.php -- run this on each node every minute via cron
// Requires libvirt/virsh on node and shared DB credentials via src/bootstrap.php
require __DIR__ . '/../src/bootstrap.php';

use VMForge\Core\DB;
use VMForge\Core\Shell;

$pdo = DB::pdo();

// Find node ID for this host by management URL host match or fallback to single node
$nodeId = null;
$host = gethostname();
$rows = $pdo->query("SELECT id, name, mgmt_url FROM nodes")->fetchAll(PDO::FETCH_ASSOC);
if ($rows) {
    foreach ($rows as $r) {
        if (!empty($r['name']) && $r['name'] === $host) { $nodeId = (int)$r['id']; break; }
    }
    if ($nodeId === null && count($rows) === 1) $nodeId = (int)$rows[0]['id'];
}
if ($nodeId === null) {
    fwrite(STDERR, "bw_sampler: cannot determine node id for host {$host}\n");
    exit(0);
}

// VMs on this node
$st = $pdo->prepare("SELECT uuid, name FROM vm_instances WHERE node_id=?");
$st->execute([$nodeId]);
$vms = $st->fetchAll(PDO::FETCH_ASSOC);

$now = time();
$periodStart = $now - 60; // assume per-minute
foreach ($vms as $vm) {
    $name = $vm['name'];
    $uuid = $vm['uuid'];

    // list interfaces
    [$code, $out, $err] = Shell::run("virsh domiflist " . escapeshellarg($name));
    if ($code !== 0) continue;
    $ifnames = [];
    foreach (explode("\n", $out) as $line) {
        // Format: Interface  Type  Source  Model  MAC
        if (preg_match('~^\s*(vnet\d+|tap\d+|.*?)[\s\t]+~', $line, $m)) {
            $if = trim($m[1]);
            if ($if !== '' && $if !== 'Interface') $ifnames[$if] = true;
        }
    }
    if (empty($ifnames)) {
        // fallback to vnet0
        $ifnames['vnet0'] = true;
    }

    foreach (array_keys($ifnames) as $ifname) {
        [$c, $statsOut, $e] = Shell::run("virsh domifstat " . escapeshellarg($name) . " " . escapeshellarg($ifname));
        if ($c !== 0) continue;
        $map = [];
        foreach (explode("\n", trim($statsOut)) as $l) {
            if (preg_match('~^(\w+)\s+(\d+)~', trim($l), $m)) {
                $map[$m[1]] = (int)$m[2];
            }
        }
        $rx_b = $map['rx_bytes'] ?? 0;
        $tx_b = $map['tx_bytes'] ?? 0;
        $rx_p = $map['rx_packets'] ?? 0;
        $tx_p = $map['tx_packets'] ?? 0;

        // Get previous sample window to compute delta
        $prev = $pdo->prepare("SELECT rx_bytes, tx_bytes, rx_packets, tx_packets FROM bandwidth_usage
                               WHERE vm_uuid=? AND interface=? ORDER BY period_end DESC LIMIT 1");
        $prev->execute([$uuid, $ifname]);
        $pr = $prev->fetch(PDO::FETCH_ASSOC);

        if ($pr) {
            $drx_b = max(0, $rx_b - (int)$pr['rx_bytes']);
            $dtx_b = max(0, $tx_b - (int)$pr['tx_bytes']);
            $drx_p = max(0, $rx_p - (int)$pr['rx_packets']);
            $dtx_p = max(0, $tx_p - (int)$pr['tx_packets']);
            // clamp absurd deltas (e.g., counter reset)
            if ($drx_b > (10*1024*1024*1024)) $drx_b = 0;
            if ($dtx_b > (10*1024*1024*1024)) $dtx_b = 0;
            if ($drx_p > 10_000_000) $drx_p = 0;
            if ($dtx_p > 10_000_000) $dtx_p = 0;
            $ins = $pdo->prepare("INSERT INTO bandwidth_usage
                (vm_uuid, interface, rx_bytes, tx_bytes, rx_packets, tx_packets, period_start, period_end)
                VALUES (?,?,?,?,?,?,FROM_UNIXTIME(?),FROM_UNIXTIME(?))");
            $ins->execute([$uuid, $ifname, $drx_b, $dtx_b, $drx_p, $dtx_p, $periodStart, $now]);
        } else {
            // seed row with zero delta; next minute will be accurate
            $ins = $pdo->prepare("INSERT INTO bandwidth_usage
                (vm_uuid, interface, rx_bytes, tx_bytes, rx_packets, tx_packets, period_start, period_end)
                VALUES (?,?,?,?,?,?,FROM_UNIXTIME(?),FROM_UNIXTIME(?))");
            $ins->execute([$uuid, $ifname, 0, 0, 0, 0, $periodStart, $now]);
        }
    }
}

echo "bw_sampler: done at ".date('c',$now)."\n";
