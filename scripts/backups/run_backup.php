#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use VMForge\Core\DB;
use VMForge\Services\Backup;

if ($argc < 2) {
    fwrite(STDERR, "Usage: scripts/backups/run_backup.php <vm-name-or-uuid>\n");
    exit(1);
}
$arg = $argv[1];

$pdo = DB::pdo();
if (strlen($arg) >= 32) {
    $st = $pdo->prepare('SELECT uuid, name FROM vm_instances WHERE uuid=? LIMIT 1');
    $st->execute([$arg]);
} else {
    $st = $pdo->prepare('SELECT uuid, name FROM vm_instances WHERE name=? LIMIT 1');
    $st->execute([$arg]);
}
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) { fwrite(STDERR, "VM not found\n"); exit(2); }

try {
    $id = Backup::backupVM((string)$row['name'], (string)$row['uuid']);
    echo "Backup created: id={$id}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(3);
}
