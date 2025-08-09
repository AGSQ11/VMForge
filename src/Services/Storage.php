<?php
namespace VMForge\Services;

use VMForge\Core\DB;
use VMForge\Core\Shell;
use PDO;

class Storage {
    public static function all(): array {
        $st = DB::pdo()->query('SELECT id, name, driver, config FROM storage_pools ORDER BY id ASC');
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function createPool(string $name, string $driver, ?array $config): void {
        $pdo = DB::pdo();
        $st = $pdo->prepare('INSERT INTO storage_pools(name, driver, config) VALUES (?,?,?)');
        $st->execute([$name, $driver, $config ? json_encode($config) : null]);
    }
    public static function resolveDiskXml(string $name, string $driver, ?array $cfg, int $sizeGb): array {
        switch ($driver) {
            case 'qcow2':
                $path = "/var/lib/libvirt/images/{$name}.qcow2";
                return [true, "qcow2 at {$path}", "<disk type='file' device='disk'><driver name='qemu' type='qcow2'/><source file='{$path}'/><target dev='vda' bus='virtio'/></disk>"];
            case 'lvmthin':
                $vg = $cfg['vg'] ?? null; $pool = $cfg['thinpool'] ?? null;
                if (!$vg || !$pool) return [false, 'missing vg/thinpool', ''];
                $lv = $name;
                $cmd = "lvcreate -T ".escapeshellarg("{$vg}/{$pool}")." -V {$sizeGb}G -n ".escapeshellarg($lv);
                Shell::run($cmd);
                $dev = "/dev/{$vg}/{$lv}";
                return [true, "lvmthin {$vg}/{$pool} -> {$dev}", "<disk type='block' device='disk'><driver name='qemu' type='raw'/><source dev='{$dev}'/><target dev='vda' bus='virtio'/></disk>"];
            case 'zfs':
                $pool = $cfg['pool'] ?? null; $prefix = $cfg['dataset'] ?? 'vmforge';
                if (!$pool) return [false, 'missing zfs pool', ''];
                $ds = $pool.'/'.trim($prefix,'/')."/{$name}";
                Shell::run("zfs create -V {$sizeGb}G ".escapeshellarg($ds));
                $dev = "/dev/zvol/{$ds}";
                return [true, "zfs zvol {$ds}", "<disk type='block' device='disk'><driver name='qemu' type='raw'/><source dev='{$dev}'/><target dev='vda' bus='virtio'/></disk>"];
            default:
                return [false, 'unknown driver', ''];
        }
    }
    public static function destroyDisk(string $name, string $driver, ?array $cfg): void {
        switch ($driver) {
            case 'qcow2':
                @unlink("/var/lib/libvirt/images/{$name}.qcow2");
                break;
            case 'lvmthin':
                $vg = $cfg['vg'] ?? null; if ($vg) Shell::run("lvremove -y ".escapeshellarg("{$vg}/{$name}"));
                break;
            case 'zfs':
                $pool = $cfg['pool'] ?? null; $prefix = $cfg['dataset'] ?? 'vmforge';
                if ($pool) {
                    $ds = $pool.'/'.trim($prefix,'/')."/{$name}";
                    Shell::run("zfs destroy -R ".escapeshellarg($ds));
                }
                break;
        }
    }
}
