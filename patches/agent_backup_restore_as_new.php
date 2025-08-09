<?php
// Append to agent/agent.php: implement BACKUP_RESTORE_AS_NEW if not present
if (!function_exists('backup_restore_as_new')) {
    function backup_restore_as_new(array $p, string $bridge): array {
        $new = $p['new_name'] ?? null; if (!$new) return [false,'missing new_name'];
        $src = $p['source'] ?? null; if (!$src) return [false,'missing source'];
        // create new disk from snapshot
        @mkdir("/var/lib/libvirt/images", 0755, true);
        $dest = "/var/lib/libvirt/images/{$new}.qcow2";
        $r = \VMForge\Core\Shell::run("qemu-img convert -O qcow2 ".escapeshellarg($src)." ".escapeshellarg($dest));
        if ($r[0] !== 0) return $r;
        // define domain minimal
        $xml = "<domain type='kvm'><name>{$new}</name><memory unit='MiB'>1024</memory><vcpu>1</vcpu><os><type arch='x86_64'>hvm</type></os><devices><disk type='file' device='disk'><driver name='qemu' type='qcow2'/><source file='{$dest}'/><target dev='vda' bus='virtio'/></disk><interface type='bridge'><source bridge='{$bridge}'/></interface><graphics type='vnc' port='-1' autoport='yes'/></devices></domain>";
        $tmp = sys_get_temp_dir()."/vmforge-restore-{$new}.xml";
        file_put_contents($tmp, $xml);
        return \VMForge\Core\Shell::run("virsh define {$tmp} && virsh start {$new}");
    }
}
// And dispatch case (manual note): ensure executeJob() can call backup_restore_as_new when type is BACKUP_RESTORE_AS_NEW.
