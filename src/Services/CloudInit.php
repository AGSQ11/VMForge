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
            $netData = "version: 2\n";
            $netData .= "ethernets:\n  ens3:\n";
            if (!empty($net['ipv4'])) {
                $v4 = $net['ipv4'];
                $netData .= "    addresses: [{$v4['address']}/{$v4['prefix']}]\n";
                if (!empty($v4['gateway'])) $netData .= "    gateway4: {$v4['gateway']}\n";
            } else {
                $netData .= "    dhcp4: true\n";
            }
            if (!empty($net['ipv6'])) {
                $v6 = $net['ipv6'];
                $netData .= "    addresses: [{$v6['address']}/{$v6['prefix']}]\n";
                if (!empty($v6['gateway'])) $netData .= "    gateway6: {$v6['gateway']}\n";
            }
            $dns = $net['dns'] ?? ['1.1.1.1','8.8.8.8'];
            $netData .= "    nameservers:\n      addresses: [".implode(', ', $dns)."]\n";
        }
        file_put_contents("{$dir}/user-data", $userData);
        file_put_contents("{$dir}/meta-data", $metaData);
        if ($netData) file_put_contents("{$dir}/network-config", $netData);
        $cmd = "cloud-localds -v ".escapeshellarg("{$dir}/seed.iso")." ".escapeshellarg("{$dir}/user-data")." ".($netData?escapeshellarg("{$dir}/network-config"):"");
        return Shell::run($cmd);
    }
}
