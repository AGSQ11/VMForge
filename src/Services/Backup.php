<?php
namespace VMForge\Services;

use VMForge\Core\DB;
use VMForge\Core\Env;
use VMForge\Core\Shell;
use VMForge\Integrations\S3;
use PDO;

class Backup {
    public static function baseDir(): string {
        $dir = rtrim(Env::get('BACKUP_DIR', '/var/lib/vmforge/backups'), '/');
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        return $dir;
    }

    public static function s3Enabled(): bool {
        return Env::get('S3_ENDPOINT','') !== '' && Env::get('S3_BUCKET','') !== '' && Env::get('S3_ACCESS_KEY','') !== '' && Env::get('S3_SECRET_KEY','') !== '';
    }

    /** Create a full QCOW2 backup of a KVM VM by name. Returns backup id. */
    public static function backupVM(string $vmName, string $vmUUID): int {
        $img = '/var/lib/libvirt/images/' . preg_replace('~[^a-zA-Z0-9._-]+~', '_', $vmName) . '.qcow2';
        if (!is_file($img)) { throw new \RuntimeException('disk image not found: ' . $img); }

        $ts = date('Ymd_His');
        $destDir = self::baseDir() . '/' . $vmUUID;
        @mkdir($destDir, 0755, true);
        $dst = $destDir . '/' . $vmName . '-' . $ts . '.qcow2';

        // convert with qemu-img (no shell interpolation)
        [$c,$o,$e] = Shell::runf('qemu-img', ['convert', '-O', 'qcow2', $img, $dst]);
        if ($c !== 0) { @unlink($dst); throw new \RuntimeException('qemu-img failed: ' . ($e?:$o)); }

        $size = filesize($dst);
        $sha  = hash_file('sha256', $dst);
        $storage = 'local';
        $s3key = null;

        if (self::s3Enabled() && Env::get('BACKUP_OFFSITE','s3') === 's3') {
            $prefix = rtrim(Env::get('S3_PREFIX','vmforge/backups'), '/');
            $s3key = $prefix . '/' . $vmUUID . '/' . basename($dst);
            $client = new S3();
            $client->putObject($s3key, $dst, 'application/x-qemu-disk');
            $storage = Env::get('DELETE_LOCAL_AFTER_UPLOAD','0') === '1' ? 's3' : 'hybrid';
            if ($storage === 's3') { @unlink($dst); }
        }

        $pdo = DB::pdo();
        $st = $pdo->prepare('INSERT INTO backups (vm_uuid, created_at, type, size_bytes, checksum_sha256, storage, path, s3_key, status) VALUES (?, NOW(), \'full\', ?, ?, ?, ?, ?, \'ready\')');
        $st->execute([$vmUUID, (int)$size, $sha, $storage, $dst, $s3key]);
        return (int)$pdo->lastInsertId();
    }

    public static function list(string $vmUUID): array {
        $pdo = DB::pdo();
        $st = $pdo->prepare('SELECT * FROM backups WHERE vm_uuid=? AND status<>\'deleted\' ORDER BY created_at DESC');
        $st->execute([$vmUUID]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function delete(int $backupId): void {
        $pdo = DB::pdo();
        $st = $pdo->prepare('SELECT id, storage, path, s3_key FROM backups WHERE id=? AND status<>\'deleted\' LIMIT 1');
        $st->execute([$backupId]);
        $b = $st->fetch(PDO::FETCH_ASSOC); if (!$b) return;

        if (($b['storage'] === 'local' || $b['storage'] === 'hybrid') && !empty($b['path']) && is_file($b['path'])) {
            @unlink($b['path']);
        }
        if (($b['storage'] === 's3' || $b['storage'] === 'hybrid') && !empty($b['s3_key']) && self::s3Enabled()) {
            try { (new S3())->deleteObject($b['s3_key']); } catch (\Throwable $e) { /* ignore */ }
        }
        $pdo->prepare('UPDATE backups SET status=\'deleted\' WHERE id=?')->execute([$backupId]);
    }

    /** Apply retention policy for VM. */
    public static function prune(string $vmUUID): array {
        $pdo = DB::pdo();
        $policy = self::policyFor($vmUUID);
        $st = $pdo->prepare('SELECT id, created_at, size_bytes FROM backups WHERE vm_uuid=? AND status=\'ready\' ORDER BY created_at DESC, id DESC');
        $st->execute([$vmUUID]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $keep = [];
        $drop = [];

        $byDay = [];
        $byWeek = [];
        $byMonth = [];
        $totalBytes = 0;

        foreach ($rows as $r) {
            $ts = strtotime($r['created_at']);
            $day = date('Y-m-d', $ts);
            $week = date('o-W', $ts);
            $month = date('Y-m', $ts);
            $totalBytes += (int)$r['size_bytes'];

            if (!isset($byDay[$day]))   $byDay[$day] = [];
            if (!isset($byWeek[$week])) $byWeek[$week] = [];
            if (!isset($byMonth[$month])) $byMonth[$month] = [];
            $byDay[$day][] = $r; $byWeek[$week][] = $r; $byMonth[$month][] = $r;
        }

        // Helper: keep first N newest from each bucket
        $toKeep = [];
        $limit = (int)($policy['keep_daily'] ?? 7);
        foreach (array_keys($byDay) as $d) { foreach (array_slice($byDay[$d], 0, 1) as $x) $toKeep[$x['id']] = true; }
        if ($limit > 0) {
            $days = array_keys($byDay);
            usort($days, fn($a,$b)=>strcmp($b,$a));
            $days = array_slice($days, 0, $limit);
            $limKeep = [];
            foreach ($days as $d) { $limKeep += array_column(array_slice($byDay[$d], 0, 1), 'id', 'id'); }
            $toKeep = array_replace([], $limKeep);
        }

        $wlim = (int)($policy['keep_weekly'] ?? 4);
        if ($wlim > 0) {
            $weeks = array_keys($byWeek); usort($weeks, fn($a,$b)=>strcmp($b,$a));
            foreach (array_slice($weeks, 0, $wlim) as $w) {
                foreach (array_slice($byWeek[$w], 0, 1) as $x) $toKeep[$x['id']] = true;
            }
        }
        $mlim = (int)($policy['keep_monthly'] ?? 12);
        if ($mlim > 0) {
            $months = array_keys($byMonth); usort($months, fn($a,$b)=>strcmp($b,$a));
            foreach (array_slice($months, 0, $mlim) as $m) {
                foreach (array_slice($byMonth[$m], 0, 1) as $x) $toKeep[$x['id']] = true;
            }
        }

        // Age cap
        $ageDays = (int)($policy['max_age_days'] ?? 0);
        $ageCut = $ageDays > 0 ? time() - ($ageDays*86400) : null;

        // Compute deletions
        foreach ($rows as $r) {
            $rid = (int)$r['id'];
            $ts = strtotime($r['created_at']);
            if ($ageCut && $ts < $ageCut) { $drop[$rid] = true; continue; }
            if (!isset($toKeep[$rid])) { $drop[$rid] = true; }
        }

        // Total size cap (apply after keep rules): delete oldest until under cap
        $sizeCap = (int)($policy['max_total_gb'] ?? 0) * 1024 * 1024 * 1024;
        if ($sizeCap > 0) {
            $acc = 0;
            foreach ($rows as $r) { if (!isset($drop[(int)$r['id']])) $acc += (int)$r['size_bytes']; }
            if ($acc > $sizeCap) {
                for ($i = count($rows)-1; $i >= 0 && $acc > $sizeCap; $i--) {
                    $rid = (int)$rows[$i]['id'];
                    if (!isset($drop[$rid])) { $drop[$rid] = true; $acc -= (int)$rows[$i]['size_bytes']; }
                }
            }
        }

        // Execute deletions
        $deleted = [];
        foreach (array_keys($drop) as $rid) {
            self::delete($rid);
            $deleted[] = $rid;
        }
        return ['deleted'=>$deleted, 'kept'=>array_keys($toKeep)];
    }

    public static function policyFor(string $vmUUID): array {
        $pdo = DB::pdo();
        $st = $pdo->prepare('SELECT keep_daily, keep_weekly, keep_monthly, max_total_gb, max_age_days, offsite, delete_local_after_upload FROM backup_policies WHERE vm_uuid=?');
        $st->execute([$vmUUID]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['keep_daily'=>7,'keep_weekly'=>4,'keep_monthly'=>12,'max_total_gb'=>200,'max_age_days'=>0,'offsite'=>'s3','delete_local_after_upload'=>0];
        }
        return $row;
    }
}
