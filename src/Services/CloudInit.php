<?php
namespace VMForge\Services;

use VMForge\Core\Shell;
use VMForge\Core\Env;

class CloudInit {
    /**
     * Generate cloud-init seed ISO
     */
    public static function buildSeedISO(
        string $dir,
        string $vmName,
        string $hostname,
        string $user,
        ?string $sshKey,
        ?string $password,
        ?array $network = null
    ): array {
        // Ensure directory exists
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // Generate user-data
        $userData = self::generateUserData($hostname, $user, $sshKey, $password);
        
        // Generate meta-data
        $metaData = self::generateMetaData($vmName, $hostname);
        
        // Generate network-config if needed
        $networkConfig = null;
        if ($network) {
            $networkConfig = self::generateNetworkConfig($network);
        }
        
        // Write files
        file_put_contents("$dir/user-data", $userData);
        file_put_contents("$dir/meta-data", $metaData);
        
        if ($networkConfig) {
            file_put_contents("$dir/network-config", $networkConfig);
        }
        
        // Build ISO
        $isoPath = "$dir/seed.iso";
        
        if ($networkConfig) {
            $cmd = sprintf(
                'cloud-localds -v %s %s %s %s',
                escapeshellarg($isoPath),
                escapeshellarg("$dir/user-data"),
                escapeshellarg("$dir/meta-data"),
                escapeshellarg("$dir/network-config")
            );
        } else {
            $cmd = sprintf(
                'cloud-localds -v %s %s %s',
                escapeshellarg($isoPath),
                escapeshellarg("$dir/user-data"),
                escapeshellarg("$dir/meta-data")
            );
        }
        
        [$code, $output, $error] = Shell::run($cmd);
        
        if ($code !== 0) {
            // Fallback to genisoimage if cloud-localds not available
            return self::buildWithGenisoimage($dir, $isoPath);
        }
        
        return [$code === 0, $code === 0 ? $isoPath : $error];
    }
    
    /**
     * Generate cloud-init user-data
     */
    private static function generateUserData(
        string $hostname,
        string $user,
        ?string $sshKey,
        ?string $password
    ): string {
        $userData = "#cloud-config\n";
        $userData .= "hostname: $hostname\n";
        $userData .= "manage_etc_hosts: true\n";
        $userData .= "preserve_hostname: false\n\n";
        
        // Configure default user
        $userData .= "users:\n";
        $userData .= "  - name: $user\n";
        $userData .= "    groups: [adm, sudo]\n";
        $userData .= "    sudo: ['ALL=(ALL) NOPASSWD:ALL']\n";
        $userData .= "    shell: /bin/bash\n";
        
        if ($sshKey) {
            $userData .= "    ssh_authorized_keys:\n";
            $userData .= "      - $sshKey\n";
        }
        
        if ($password) {
            // Hash password for security
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $userData .= "    lock_passwd: false\n";
            $userData .= "    passwd: $hashedPassword\n";
        } else {
            $userData .= "    lock_passwd: true\n";
        }
        
        // Package updates
        $userData .= "\npackage_update: true\n";
        $userData .= "package_upgrade: true\n\n";
        
        // Install useful packages
        $userData .= "packages:\n";
        $userData .= "  - qemu-guest-agent\n";
        $userData .= "  - cloud-init\n";
        $userData .= "  - curl\n";
        $userData .= "  - wget\n";
        $userData .= "  - vim\n";
        $userData .= "  - htop\n\n";
        
        // Enable services
        $userData .= "runcmd:\n";
        $userData .= "  - systemctl enable qemu-guest-agent\n";
        $userData .= "  - systemctl start qemu-guest-agent\n";
        
        // Power state
        $userData .= "\npower_state:\n";
        $userData .= "  mode: reboot\n";
        $userData .= "  timeout: 30\n";
        $userData .= "  condition: true\n";
        
        return $userData;
    }
    
    /**
     * Generate cloud-init meta-data
     */
    private static function generateMetaData(string $vmName, string $hostname): string {
        $metaData = "instance-id: $vmName\n";
        $metaData .= "local-hostname: $hostname\n";
        
        return $metaData;
    }
    
    /**
     * Generate network configuration
     */
    private static function generateNetworkConfig(array $network): string {
        $config = "version: 2\n";
        $config .= "ethernets:\n";
        $config .= "  eth0:\n";
        
        if (!empty($network['dhcp4'])) {
            $config .= "    dhcp4: true\n";
        } else if (!empty($network['ipv4'])) {
            $ipv4 = $network['ipv4'];
            $config .= "    dhcp4: false\n";
            $config .= "    addresses:\n";
            $config .= "      - {$ipv4['address']}/{$ipv4['prefix']}\n";
            
            if (!empty($ipv4['gateway'])) {
                $config .= "    gateway4: {$ipv4['gateway']}\n";
            }
        }
        
        if (!empty($network['dhcp6'])) {
            $config .= "    dhcp6: true\n";
        } else if (!empty($network['ipv6'])) {
            $ipv6 = $network['ipv6'];
            $config .= "    dhcp6: false\n";
            $config .= "    addresses:\n";
            $config .= "      - {$ipv6['address']}/{$ipv6['prefix']}\n";
            
            if (!empty($ipv6['gateway'])) {
                $config .= "    gateway6: {$ipv6['gateway']}\n";
            }
        }
        
        // DNS servers
        if (!empty($network['dns'])) {
            $config .= "    nameservers:\n";
            $config .= "      addresses:\n";
            foreach ($network['dns'] as $dns) {
                $config .= "        - $dns\n";
            }
        }
        
        return $config;
    }
    
    /**
     * Fallback ISO creation with genisoimage
     */
    private static function buildWithGenisoimage(string $dir, string $isoPath): array {
        $cmd = sprintf(
            'genisoimage -output %s -volid cidata -joliet -rock %s',
            escapeshellarg($isoPath),
            escapeshellarg($dir)
        );
        
        [$code, $output, $error] = Shell::run($cmd);
        
        return [$code === 0, $code === 0 ? $isoPath : $error];
    }
}
