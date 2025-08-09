#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use VMForge\Core\DB;

$pdo = DB::pdo();

// enqueue a collection on each node
$nodes = $pdo->query('SELECT id FROM nodes')->fetchAll(PDO::FETCH_COLUMN);
foreach ($nodes as $nid) {
    $st = $pdo->prepare("INSERT INTO jobs (node_id, type, payload, status, created_at) VALUES (?, 'BANDWIDTH_COLLECT', '{}', 'pending', NOW())");
    $st->execute([(int)$nid]);
}

// ingest recent finished
$st = $pdo->prepare("SELECT j.id, j.log FROM jobs j WHERE j.type='BANDWIDTH_COLLECT' AND j.status='done' AND j.finished_at > (NOW() - INTERVAL 15 MINUTE)");
$st->execute();
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $data = json_decode((string)$row['log'], true);
    if (!$data || empty($data['entries'])) continue;
    $ts = (int)($data['ts'] ?? time());
    $tend = date('Y-m-d H:i:s', $ts);
    foreach ($data['entries'] as $ent) {
        $name = (string)($ent['name'] ?? '');
        $if   = (string)($ent['if'] ?? '');
        $rx   = (int)($ent['rx_bytes'] ?? 0);
        $tx   = (int)($ent['tx_bytes'] ?? 0);
        if ($name === '' || $if === '') continue;

        $vmq = $pdo->prepare('SELECT uuid FROM vm_instances WHERE name=? LIMIT 1');
        $vmq->execute([$name]);
        $uuid = $vmq->fetchColumn();
        if (!$uuid) continue;

        $cc = $pdo->prepare('SELECT last_rx, last_tx, updated_at FROM bandwidth_counters WHERE vm_uuid=? AND interface=?');
        $cc->execute([$uuid, $if]);
        $c = $cc->fetch(PDO::FETCH_ASSOC);

        $tstart = $c ? $c['updated_at'] : date('Y-m-d H:i:s', $ts - 300);
        $drx = $c ? max(0, $rx - (int)$c['last_rx']) : $rx;
        $dtx = $c ? max(0, $tx - (int)$c['last_tx']) : $tx;

        $ins = $pdo->prepare('INSERT INTO bandwidth_usage (vm_uuid, interface, rx_bytes, tx_bytes, period_start, period_end) VALUES (?,?,?,?,?,?)');
        $ins->execute([$uuid, $if, $drx, $dtx, $tstart, $tend]);

        $up = $pdo->prepare('REPLACE INTO bandwidth_counters (vm_uuid, interface, last_rx, last_tx, updated_at) VALUES (?,?,?,?,NOW())');
        $up->execute([$uuid, $if, $rx, $tx]);
    }
}
echo "bandwidth collection/ingest complete\n";
