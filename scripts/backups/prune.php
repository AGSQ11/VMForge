#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use VMForge\Core\DB;
use VMForge\Services\Backup;

$pdo = DB::pdo();
$vm = $argv[1] ?? null;
$vms = [];

if ($vm) {
    if (strlen($vm) >= 32) {
        $st = $pdo->prepare('SELECT uuid FROM vm_instances WHERE uuid=?');
        $st->execute([$vm]);
    } else {
        $st = $pdo->prepare('SELECT uuid FROM vm_instances WHERE name=?');
        $st->execute([$vm]);
    }
    $uuid = $st->fetchColumn();
    if (!$uuid) { fwrite(STDERR, "VM not found\n"); exit(2); }
    $vms = [$uuid];
} else {
    $vms = $pdo->query('SELECT uuid FROM vm_instances')->fetchAll(PDO::FETCH_COLUMN);
}

$deleted = 0;
foreach ($vms as $uuid) {
    $res = Backup::prune((string)$uuid);
    $deleted += count($res['deleted']);
    echo "VM {$uuid}: deleted=".count($res['deleted'])." kept=".count($res['kept'])."\n";
}
echo "Total deleted: {$deleted}\n";
