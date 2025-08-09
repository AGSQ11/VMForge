<?php
namespace VMForge\Services;

use VMForge\Core\DB;
use VMForge\Core\Shell;
use PDO;

class ISOStore {
    public static function all(): array {
        $st = DB::pdo()->query('SELECT id,name,url,checksum,local_path,created_at FROM isos ORDER BY id DESC');
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function add(string $name, string $url, ?string $checksum): void {
        $pdo = DB::pdo();
        $st = $pdo->prepare('INSERT INTO isos(name,url,checksum) VALUES (?,?,?)');
        $st->execute([$name, $url, $checksum]);
    }
    public static function get(int $id): ?array {
        $st = DB::pdo()->prepare('SELECT * FROM isos WHERE id=?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
    public static function ensureLocal(int $id): ?string {
        $iso = self::get($id);
        if (!$iso) return null;
        if (!empty($iso['local_path']) && file_exists($iso['local_path'])) return $iso['local_path'];
        @mkdir('/var/lib/vmforge/iso', 0755, true);
        $dest = '/var/lib/vmforge/iso/' . preg_replace('~[^a-zA-Z0-9._-]~', '_', $iso['name']) . '.iso';
        $cmd = "curl -L --fail --retry 3 -o ".escapeshellarg($dest)." ".escapeshellarg($iso['url']);
        Shell::run($cmd);
        if (!empty($iso['checksum'])) {
            // support sha256:xxxx
            if (strpos($iso['checksum'], 'sha256:') === 0) {
                $want = substr($iso['checksum'], 7);
                [$c,$o,$e] = Shell::run("sha256sum ".escapeshellarg($dest)." | awk '{print $1}'");
                if ($c !== 0 || trim($o) !== $want) { @unlink($dest); return null; }
            }
        }
        $pdo = DB::pdo();
        $pdo->prepare('UPDATE isos SET local_path=? WHERE id=?')->execute([$dest, $id]);
        return $dest;
    }
}
