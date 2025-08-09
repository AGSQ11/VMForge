<?php
namespace VMForge\Services;
use VMForge\Core\DB;
use VMForge\Core\Shell;
use PDO;

class Backup {
    public static function localDir(): string {
        return $_ENV['BACKUP_DIR'] ?? '/var/lib/vmforge/backups';
    }

    public static function ensureLocal(): void {
        $d = self::localDir();
        if (!is_dir($d)) mkdir($d, 0755, true);
    }

    public static function s3Enabled(): bool {
        return !empty($_ENV['S3_BUCKET']);
    }

    public static function uploadS3(string $path, string $key): array {
        $bucket = $_ENV['S3_BUCKET'] ?? '';
        $region = $_ENV['S3_REGION'] ?? '';
        if (!$bucket) return [false, 'S3 not configured'];
        $cmd = "aws s3 cp ".escapeshellarg($path)." ".escapeshellarg("s3://{$bucket}/{$key}") . ($region ? " --region ".escapeshellarg($region) : "");
        return Shell::run($cmd);
    }
}
