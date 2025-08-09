<?php
namespace VMForge\Services;
use VMForge\Core\Shell;

class CloudInit {
    public static function buildSeedISO(string $dir, string $vmName, string $hostname, string $user, ?string $sshKey, ?string $password, ?array $net): array {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $userData = "#cloud-config\n";
        $userData .= "hostname: {$hostname}\n";
        $userData .= "users:\n  - name: {$user}\n    sudo: ['ALL=(ALL) NOPASSWD:ALL']\n    shell: /bin/bash\n";
        if ($sshKey) $userData .= "    ssh_authorized_keys:\n      - {$sshKey}\n";
        if ($password) $userData .= "    plain_text_passwd: '{$password}'\n    lock_passwd: false\n";
        $metaData = "instance-id: {$vmName}\nlocal-hostname: {$hostname}\n";

        $netData = '';
        if ($net) {
            // netplan v2 format minimal for a single interface
            $netData = "version: 2\n";
            $netData .= "ethernets:\n  ens3:\n    dhcp4: false\n    addresses: [{$net['address']}/{$net['prefix']}]\n    gateway4: {$net['gateway']}\n    nameservers:\n      addresses: [".implode(', ', array_map(fn($d)=>$d, $net['dns'] ?? ['1.1.1.1']))."]\n";
        }
        $tmp = rtrim($dir, '/');
        file_put_contents("{$tmp}/user-data", $userData);
        file_put_contents("{$tmp}/meta-data", $metaData);
        if ($netData) file_put_contents("{$tmp}/network-config", $netData);
        $cmd = "cloud-localds -v ".escapeshellarg("{$tmp}/seed.iso")." ".escapeshellarg("{$tmp}/user-data")." ".($netData?escapeshellarg("{$tmp}/network-config"):"");
        return Shell::run($cmd);
    }
}
