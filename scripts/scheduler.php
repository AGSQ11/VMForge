#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use VMForge\Core\DB;
use VMForge\Models\Job;
use PDO;

$now = new DateTime('now');
$minute = (int)$now->format('i');
$hour   = (int)$now->format('G');
$dmon   = (int)$now->format('j');
$mon    = (int)$now->format('n');
$dw     = (int)$now->format('w');

function cronMatch(string $expr, int $min, int $hour, int $dmon, int $mon, int $dw): bool {
    $parts = preg_split('/\s+/', trim($expr));
    if (count($parts) !== 5) return false;
    [$m,$h,$dm,$mo,$dwf] = $parts;
    $fn = function($field, $val) {
        if ($field === '*') return true;
        foreach (explode(',', $field) as $seg) {
            if (str_contains($seg, '/')) {
                [$base,$step] = explode('/', $seg, 2);
                $step = (int)$step;
                $range = $base==='*' ? [0,59] : array_map('intval', explode('-', $base, 2));
                for ($i=$range[0]; $i<=$range[1]; $i+=$step) if ($i===$val) return true;
            } elseif (str_contains($seg, '-')) {
                [$a,$b] = array_map('intval', explode('-', $seg, 2));
                if ($val>=$a && $val<=$b) return true;
            } else {
                if ((int)$seg === $val) return true;
            }
        }
        return false;
    };
    return $fn($m,$min) && $fn($h,$hour) && $fn($dm,$dmon) && $fn($mo,$mon) && $fn($dwf,$dw);
}

$pdo = DB::pdo();
$st = $pdo->query("SELECT * FROM schedules WHERE enabled=1");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (!cronMatch($row['cron'], $minute, $hour, $dmon, $mon, $dw)) continue;
    $payload = json_decode($row['payload'] ?? '[]', true);
    if ($row['kind'] === 'backup') {
        $vmuuid = $row['vm_uuid'];
        $vm = $pdo->prepare('SELECT * FROM vm_instances WHERE uuid=? LIMIT 1');
        $vm->execute([$vmuuid]);
        $v = $vm->fetch(PDO::FETCH_ASSOC);
        if (!$v) continue;
        $snap = 'auto-' . date('Ymd-His');
        Job::enqueue((int)$v['node_id'], 'SNAPSHOT_CREATE', ['name'=>$v['name'], 'snapshot'=>$snap]);
        $target = ($payload['target'] ?? 'local') === 's3' ? 's3' : 'local';
        Job::enqueue((int)$v['node_id'], 'BACKUP_UPLOAD', ['name'=>$v['name'], 'snapshot'=>$snap, 'target'=>$target]);
    }
}
