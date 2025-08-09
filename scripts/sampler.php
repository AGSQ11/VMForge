#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use VMForge\Core\DB;
use VMForge\Core\Shell;

function node_metrics(): array {
    // crude: read /proc, average CPU busy %, mem used MB
    $load1 = sys_getloadavg()[0] ?? 0.0;
    $mem = @file_get_contents('/proc/meminfo') ?: '';
    preg_match('/MemTotal:\s+(\d+)/', $mem, $m1);
    preg_match('/MemAvailable:\s+(\d+)/', $mem, $m2);
    $totalKb = isset($m1[1]) ? (int)$m1[1] : 0;
    $availKb = isset($m2[1]) ? (int)$m2[1] : 0;
    $usedMb = $totalKb > 0 ? (int)(($totalKb - $availKb)/1024) : null;
    $cpuPct = min(100.0, max(0.0, $load1 * 100.0 / max(1, (int)shell_exec('nproc'))));
    return ['cpu_pct'=>$cpuPct, 'mem_used_mb'=>$usedMb];
}

function vm_metrics(): array {
    // Use virsh domstats --vcpu --balloon --interface -- for all domains
    [$c,$o,$e] = Shell::run("virsh domstats --vcpu --balloon --interface --raw --state | tr -d '\r'");
    if ($c !== 0) return [];
    $out = [];
    $cur = [];
    foreach (explode("\n", trim($o)) as $line) {
        if ($line === '') continue;
        if (strpos($line, 'dom.') === 0) { // new VM block header like dom.12345.name=foo
            if (!empty($cur)) { $out[] = $cur; $cur = []; }
        }
        if (preg_match('/name=(.+)$/', $line, $m)) $cur['name'] = trim($m[1]);
        if (preg_match('/state.state=(\d+)/', $line, $m)) $cur['state'] = (int)$m[1];
        if (preg_match('/vcpu.current.vcpu.time=(\d+)/', $line, $m)) $cur['vcpu_time'] = (int)$m[1];
        if (preg_match('/balloon.current=(\d+)/', $line, $m)) $cur['mem'] = (int)$m[1] / 1024;
        if (preg_match('/net.(\d+).rx.bytes=(\d+)/', $line, $m)) $cur['rx'] = (int)$m[2];
        if (preg_match('/net.(\d+).tx.bytes=(\d+)/', $line, $m)) $cur['tx'] = (int)$m[2];
    }
    if (!empty($cur)) $out[] = $cur;
    return $out;
}

$pdo = DB::pdo();
$host = trim(shell_exec('hostname') ?: 'node');

$node = node_metrics();
$st = $pdo->prepare('INSERT INTO metrics_node(hostname, cpu_pct, mem_used_mb) VALUES (?,?,?)');
$st->execute([$host, $node['cpu_pct'], $node['mem_used_mb']]);

foreach (vm_metrics() as $m) {
    // map name->uuid
    $st = $pdo->prepare('SELECT uuid FROM vm_instances WHERE name=? LIMIT 1');
    $st->execute([$m['name']]);
    $uuid = $st->fetchColumn(); if (!$uuid) continue;
    $ins = $pdo->prepare('INSERT INTO metrics_vm(vm_uuid, cpu_pct, mem_used_mb, rx_bytes, tx_bytes) VALUES (?,?,?,?,?)');
    $ins->execute([$uuid, null, $m['mem'] ?? null, $m['rx'] ?? null, $m['tx'] ?? null]);
}

echo "collected\n";
