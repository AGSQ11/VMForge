<?php
namespace VMForge\Services;
use VMForge\Core\DB;
use VMForge\Core\Shell;
use PDO;

class ImageManager {
    private string $root = '/var/lib/vmforge/images';

    public function ensureRoot(): void {
        if (!is_dir($this->root)) {
            mkdir($this->root, 0755, true);
        }
    }
    public function pathFor(int $imageId): ?string {
        $st = DB::pdo()->prepare('SELECT * FROM images WHERE id=?');
        $st->execute([$imageId]);
        $img = $st->fetch(PDO::FETCH_ASSOC);
        if (!$img) return null;
        $safe = preg_replace('~[^a-zA-Z0-9._-]+~', '_', $img['name']);
        $ext = $img['type'] === 'kvm' ? 'qcow2' : 'tar.xz';
        return "{$this->root}/{$imageId}_{$safe}.{$ext}";
    }
    public function downloadIfMissing(int $imageId): array {
        $st = DB::pdo()->prepare('SELECT * FROM images WHERE id=?');
        $st->execute([$imageId]);
        $img = $st->fetch(PDO::FETCH_ASSOC);
        if (!$img) return [false, "image not found"];
        $this->ensureRoot();
        $path = $this->pathFor($imageId);
        if (file_exists($path)) return [true, $path];
        if (empty($img['source_url'])) return [false, "no source_url for image"];
        [$c,$o,$e] = Shell::run("curl -L --fail -o ".escapeshellarg($path)." ".escapeshellarg($img['source_url']));
        if ($c !== 0) return [false, $e ?: $o];
        if (!empty($img['sha256'])) {
            [$c2,$o2] = [0, trim(shell_exec("sha256sum ".escapeshellarg($path)." | awk '{print $1}'") ?? '')];
            if (strtolower($o2) !== strtolower($img['sha256'])) return [false, "checksum mismatch"];
        }
        return [true, $path];
    }
}
