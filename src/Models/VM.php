<?php
namespace VMForge\Models;
use VMForge\Core\DB;
use PDO;
class VM {
    public static function all(): array {
        $st = DB::pdo()->query('SELECT * FROM vm_instances ORDER BY id DESC');
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function create(array $d): int {
        $st = DB::pdo()->prepare('INSERT INTO vm_instances (uuid, node_id, name, type, vcpus, memory_mb, disk_gb, image_id, bridge, ip_address) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$d['uuid'],$d['node_id'],$d['name'],$d['type'],$d['vcpus'],$d['memory_mb'],$d['disk_gb'],$d['image_id'],$d['bridge'],$d['ip_address']]);
        return (int)DB::pdo()->lastInsertId();
    }
}
