<?php
namespace VMForge\Services;

use VMForge\Core\Env;
use VMForge\Core\DB;
use PDO;

class ISOStore {
    /** Base directory for ISO files (shared or local on master). */
    public static function dir(): string {
        $dir = rtrim(Env::get('ISO_DIR', '/var/lib/vmforge/isos'), '/');
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        return $dir;
    }

    /** Register a local file: move into ISO_DIR, compute sha256/size, insert row; return id. */
    public static function registerLocal(string $filePath, array $meta = []): int {
        if (!is_file($filePath)) { throw new \RuntimeException('file not found'); }
        $name = $meta['name'] ?? basename($filePath);
        $safeName = preg_replace('~[^a-zA-Z0-9._-]+~', '_', $name);
        $dest = self::dir() . '/' . $safeName;

        // If uploading into same FS, rename; else copy
        if (!@rename($filePath, $dest)) {
            if (!@copy($filePath, $dest)) { throw new \RuntimeException('cannot move ISO'); }
            @unlink($filePath);
        }

        $size = filesize($dest);
        $sha256 = hash_file('sha256', $dest);

        $pdo = DB::pdo();
        $st = $pdo->prepare('INSERT INTO iso_library (name, filename, size_bytes, sha256, os_type, os_version, architecture, bootable, public, owner_id, storage_path, download_url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([
            $name,
            $safeName,
            (int)$size,
            $sha256,
            $meta['os_type'] ?? null,
            $meta['os_version'] ?? null,
            $meta['architecture'] ?? null,
            1,
            (int)($meta['public'] ?? 0),
            $meta['owner_id'] ?? null,
            $dest,
            self::composeDownloadUrl($safeName)
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** Import from remote URL to ISO_DIR (streaming), verify (optional) sha256, insert row; returns [id, path] */
    public static function importUrl(string $url, ?string $name = null, ?string $expectSha256 = null): array {
        $basename = $name ?? basename(parse_url($url, PHP_URL_PATH) ?? 'download.iso');
        $safeName = preg_replace('~[^a-zA-Z0-9._-]+~', '_', $basename);
        $dest = self::dir() . '/' . $safeName;

        $fp = fopen($dest . '.part', 'wb');
        if (!$fp) throw new \RuntimeException('cannot open dest for writing');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 0, // long downloads
            CURLOPT_FAILONERROR => true,
            CURLOPT_USERAGENT => 'VMForge-ISOStore/1.0',
        ]);
        $ok = curl_exec($ch);
        $cerr = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($ok === false) { @unlink($dest . '.part'); throw new \RuntimeException('download failed: ' . $cerr); }

        // finalize
        rename($dest . '.part', $dest);

        $sha256 = hash_file('sha256', $dest);
        if ($expectSha256 && !hash_equals($expectSha256, $sha256)) {
            @unlink($dest);
            throw new \RuntimeException('sha256 mismatch');
        }

        $size = filesize($dest);
        $pdo = DB::pdo();
        $st = $pdo->prepare('INSERT INTO iso_library (name, filename, size_bytes, sha256, bootable, public, storage_path, download_url) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute([$basename, $safeName, (int)$size, $sha256, 1, 0, $dest, self::composeDownloadUrl($safeName)]);
        $id = (int)$pdo->lastInsertId();

        return [$id, $dest];
    }

    /** Ensure the ISO exists locally (on agent). If not, try download_url to fetch. Returns path or null. */
    public static function ensureLocal(int $isoId): ?string {
        $pdo = DB::pdo();
        $st = $pdo->prepare('SELECT filename, storage_path, download_url, sha256 FROM iso_library WHERE id=?');
        $st->execute([$isoId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        // existing local path?
        $p = (string)$row['storage_path'];
        if ($p && is_file($p)) return $p;

        // try ISO_DIR
        $dest = self::dir() . '/' . (string)$row['filename'];
        if (is_file($dest)) return $dest;

        // fallback: download_url
        $url = (string)($row['download_url'] ?? '');
        if ($url === '') return null;

        $fp = fopen($dest . '.part', 'wb');
        if (!$fp) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FAILONERROR => true,
            CURLOPT_USERAGENT => 'VMForge-Agent/1.0',
        ]);
        $ok = curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        if ($ok === false) { @unlink($dest . '.part'); return null; }
        rename($dest . '.part', $dest);

        // verify sha256
        $sha = hash_file('sha256', $dest);
        if ($row['sha256'] && !hash_equals((string)$row['sha256'], $sha)) {
            @unlink($dest);
            return null;
        }
        return $dest;
    }

    /** Compose public download URL if ISO_BASE_URL is configured. */
    public static function composeDownloadUrl(string $filename): ?string {
        $base = rtrim(Env::get('ISO_BASE_URL', ''), '/');
        if ($base === '') return null;
        return $base . '/' . rawurlencode($filename);
    }
}
