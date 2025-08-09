<?php
namespace VMForge\Services;

use VMForge\Core\Shell;

class ZFS {
    public static function datasetPath(string $pool, string $dataset): string {
        $ds = trim($dataset, '/');
        if ($pool !== '') return $pool . '/' . $ds;
        return $ds;
    }
    public static function vmDataset(string $vmName): string {
        // convention: pool/vmforge/<vmName>
        $pool = $_ENV['ZFS_POOL'] ?? 'tank';
        $base = $_ENV['ZFS_BASE'] ?? 'vmforge';
        return self::datasetPath($pool, $base . '/' . $vmName);
    }
    public static function snapName(string $vmName): string {
        $ts = date('Ymd-His');
        return $vmName . '@backup-' . $ts;
    }
    public static function run(string $cmd): array {
        return Shell::run($cmd);
    }
}
