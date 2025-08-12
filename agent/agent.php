#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use VMForge\Core\Env;
use VMForge\Core\Shell;
use VMForge\Core\DB;
use VMForge\Services\ISOStore;
use VMForge\Services\CloudInit;
use VMForge\Services\ImageManager;

// Configuration
$controller = Env::get('AGENT_CONTROLLER_URL', 'http://localhost:8080');
$token = Env::get('AGENT_NODE_TOKEN', '');
$bridge = Env::get('AGENT_BRIDGE', 'br0');
$pollInterval = (int)Env::get('AGENT_POLL_INTERVAL', '5');
$nodeId = null;

// Validate configuration
if (empty($token)) {
    fwrite(STDERR, "Error: AGENT_NODE_TOKEN not configured\n");
    exit(1);
}

echo "VMForge Agent starting...\n";
echo "Controller: $controller\n";
echo "Bridge: $bridge\n";
echo "Poll Interval: {$pollInterval}s\n\n";

// Main loop
while (true) {
    try {
        $job = pollJob($controller, $token);
        
        if ($job) {
            echo "[" . date('Y-m-d H:i:s') . "] Processing job #{$job['id']} ({$job['type']})\n";
            
            [$success, $log] = executeJob($job['type'], json_decode($job['payload'], true), $bridge);
            
            $status = $success ? 'done' : 'failed';
            echo "[" . date('Y-m-d H:i:s') . "] Job #{$job['id']} $status\n";
            
            ackJob($controller, $job['id'], $status, $log);
        }
        
        sleep($pollInterval);
    } catch (\Exception $e) {
        error_log("Agent error: " . $e->getMessage());
        sleep($pollInterval);
    }
}

/**
 * Poll for new job from controller
 */
function pollJob(string $controller, string $token): ?array {
    $ch = curl_init("$controller/agent/poll");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['token' => $token],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        return null;
    }
    
    $data = json_decode($response, true);
    return $data['job'] ?? null;
}

/**
 * Acknowledge job completion
 */
function ackJob(string $controller, int $id, string $status, string $log): void {
    $ch = curl_init("$controller/agent/ack");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'id' => $id,
            'status' => $status,
            'log' => substr($log, 0, 65535) // Limit log size
        ],
        CURLOPT_TIMEOUT => 10
    ]);
    
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Execute job based on type
 */
function executeJob(string $type, array $payload, string $bridge): array {
    try {
        switch ($type) {
            case 'KVM_CREATE':
                return createKVM($payload, $bridge);
            
            case 'KVM_START':
                return kvmAction('start', $payload['name']);
            
            case 'KVM_STOP':
                return kvmAction('shutdown', $payload['name']);
            
            case 'KVM_REBOOT':
                return kvmAction('reboot', $payload['name']);
            
            case 'KVM_DELETE':
                return deleteKVM($payload['name']);
            
            case 'KVM_CONSOLE_OPEN':
                return openConsole($payload);
            
            case 'KVM_CONSOLE_CLOSE':
                return closeConsole($payload);
            
            case 'KVM_REINSTALL':
                return reinstallKVM($payload);
            
            case 'LXC_CREATE':
                return createLXC($payload, $bridge);
            
            case 'LXC_START':
                return lxcAction('start', $payload['name']);
            
            case 'LXC_STOP':
                return lxcAction('stop', $payload['name']);
            
            case 'LXC_DELETE':
                return deleteLXC($payload['name']);
            
            case 'NET_SETUP':
                return setupNetwork($payload, $bridge);
            
            case 'NET_ANTISPOOF':
                return setupAntispoof($payload);
            
            case 'NET_ROUTE_GW':
                return setupGateway($payload);
            
            case 'NET6_RA_SETUP':
                return setupIPv6RA($payload);
            
            case 'SNAPSHOT_CREATE':
                return createSnapshot($payload);
            
            case 'SNAPSHOT_DELETE':
                return deleteSnapshot($payload);
            
            case 'SNAPSHOT_RESTORE':
                return restoreSnapshot($payload);
            
            case 'BACKUP_CREATE':
                return createBackup($payload);
            
            case 'BACKUP_RESTORE':
                return restoreBackup($payload);
            
            case 'DISK_RESIZE':
                return resizeDisk($payload);
            
            case 'FW_SYNC':
                return syncFirewall($payload);
            
            case 'ZFS_BACKUP':
                return zfsBackup($payload);
            
            case 'ZFS_RESTORE':
                return zfsRestore($payload);
            
            case 'ZFS_PRUNE':
                return zfsPrune($payload);
            
            default:
                return [false, "Unknown job type: $type"];
        }
    } catch (\Exception $e) {
        return [false, $e->getMessage()];
    }
}

/**
 * Create KVM virtual machine
 */
function createKVM(array $payload, string $defaultBridge): array {
    $uuid = $payload['uuid'] ?? uniqid('vm-');
    $name = $payload['name'] ?? "vm-$uuid";
    $vcpus = (int)($payload['vcpus'] ?? 2);
    $memory = (int)($payload['memory_mb'] ?? 2048);
    $diskSize = (int)($payload['disk_gb'] ?? 20);
    $bridge = $payload['bridge'] ?? $defaultBridge;
    $imageId = (int)($payload['image_id'] ?? 0);
    $storageType = $payload['storage_type'] ?? 'qcow2';
    $vlanTag = $payload['vlan_tag'] ?? null;
    $macAddress = $payload['mac_address'] ?? generateMac();
    
    // Paths
    $baseDir = "/var/lib/vmforge/vms/$name";
    $diskPath = "$baseDir/disk.$storageType";
    
    // Create VM directory
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0755, true);
    }
    
    // Create disk
    if ($imageId > 0) {
        // Clone from base image
        $imagePath = ImageManager::ensureLocal($imageId);
        if (!$imagePath) {
            return [false, "Failed to get base image"];
        }
        
        [$code, $out, $err] = Shell::runf('qemu-img', [
            'create', '-f', $storageType, '-b', $imagePath, '-F', 'qcow2', $diskPath, "{$diskSize}G"
        ]);
        
        if ($code !== 0) {
            return [false, "Failed to create disk: $err"];
        }
    } else {
        // Create new empty disk
        [$code, $out, $err] = Shell::runf('qemu-img', [
            'create', '-f', $storageType, $diskPath, "{$diskSize}G"
        ]);
        
        if ($code !== 0) {
            return [false, "Failed to create disk: $err"];
        }
    }
    
    // Generate cloud-init ISO if credentials provided
    $cloudInitISO = null;
    if (!empty($payload['ssh_key']) || !empty($payload['password'])) {
        $hostname = $payload['hostname'] ?? $name;
        $user = $payload['username'] ?? 'vmforge';
        
        $network = null;
        if (!empty($payload['ip_address'])) {
            $network = [
                'ipv4' => [
                    'address' => $payload['ip_address'],
                    'prefix' => $payload['ip_prefix'] ?? '24',
                    'gateway' => $payload['gateway'] ?? null
                ],
                'dns' => explode(',', $payload['dns'] ?? '1.1.1.1,8.8.8.8')
            ];
        }
        
        [$success, $result] = CloudInit::buildSeedISO(
            $baseDir,
            $name,
            $hostname,
            $user,
            $payload['ssh_key'] ?? null,
            $payload['password'] ?? null,
            $network
        );
        
        if ($success) {
            $cloudInitISO = $result;
        }
    }
    
    // Build network interface XML
    $netXml = buildNetworkXml($bridge, $macAddress, $vlanTag);
    
    // Build VM XML
    $xml = buildVmXml($name, $uuid, $vcpus, $memory, $diskPath, $storageType, $netXml, $cloudInitISO);
    
    // Define and start VM
    $xmlFile = "$baseDir/domain.xml";
    file_put_contents($xmlFile, $xml);
    
    [$code, $out, $err] = Shell::runf('virsh', ['define', $xmlFile]);
    if ($code !== 0) {
        return [false, "Failed to define VM: $err"];
    }
    
    [$code, $out, $err] = Shell::runf('virsh', ['start', $name]);
    if ($code !== 0) {
        return [false, "Failed to start VM: $err"];
    }
    
    return [true, "VM $name created successfully"];
}

/**
 * Build VM XML for libvirt
 */
function buildVmXml(
    string $name,
    string $uuid,
    int $vcpus,
    int $memory,
    string $diskPath,
    string $diskType,
    string $networkXml,
    ?string $cloudInitISO = null
): string {
    $memoryKb = $memory * 1024;
    
    $xml = <<<XML
<domain type='kvm'>
  <name>$name</name>
  <uuid>$uuid</uuid>
  <memory unit='KiB'>$memoryKb</memory>
  <currentMemory unit='KiB'>$memoryKb</currentMemory>
  <vcpu placement='static'>$vcpus</vcpu>
  <os>
    <type arch='x86_64' machine='pc-q35-6.2'>hvm</type>
    <boot dev='hd'/>
  </os>
  <features>
    <acpi/>
    <apic/>
    <vmport state='off'/>
  </features>
  <cpu mode='host-passthrough' check='none'>
    <topology sockets='1' cores='$vcpus' threads='1'/>
  </cpu>
  <clock offset='utc'>
    <timer name='rtc' tickpolicy='catchup'/>
    <timer name='pit' tickpolicy='delay'/>
    <timer name='hpet' present='no'/>
  </clock>
  <on_poweroff>destroy</on_poweroff>
  <on_reboot>restart</on_reboot>
  <on_crash>destroy</on_crash>
  <pm>
    <suspend-to-mem enabled='no'/>
    <suspend-to-disk enabled='no'/>
  </pm>
  <devices>
    <emulator>/usr/bin/qemu-system-x86_64</emulator>
    <disk type='file' device='disk'>
      <driver name='qemu' type='$diskType' cache='writeback' io='threads'/>
      <source file='$diskPath'/>
      <target dev='vda' bus='virtio'/>
      <address type='pci' domain='0x0000' bus='0x04' slot='0x00' function='0x0'/>
    </disk>
XML;

    // Add cloud-init ISO if provided
    if ($cloudInitISO) {
        $xml .= <<<XML
    <disk type='file' device='cdrom'>
      <driver name='qemu' type='raw'/>
      <source file='$cloudInitISO'/>
      <target dev='sda' bus='sata'/>
      <readonly/>
      <address type='drive' controller='0' bus='0' target='0' unit='0'/>
    </disk>
XML;
    }
    
    $xml .= $networkXml;
    
    $xml .= <<<XML
    <serial type='pty'>
      <target type='isa-serial' port='0'>
        <model name='isa-serial'/>
      </target>
    </serial>
    <console type='pty'>
      <target type='serial' port='0'/>
    </console>
    <channel type='unix'>
      <target type='virtio' name='org.qemu.guest_agent.0'/>
      <address type='virtio-serial' controller='0' bus='0' port='1'/>
    </channel>
    <input type='tablet' bus='usb'>
      <address type='usb' bus='0' port='1'/>
    </input>
    <input type='mouse' bus='ps2'/>
    <input type='keyboard' bus='ps2'/>
    <graphics type='vnc' port='-1' autoport='yes' listen='0.0.0.0'>
      <listen type='address' address='0.0.0.0'/>
    </graphics>
    <video>
      <model type='qxl' ram='65536' vram='65536' vgamem='16384' heads='1' primary='yes'/>
      <address type='pci' domain='0x0000' bus='0x00' slot='0x01' function='0x0'/>
    </video>
    <memballoon model='virtio'>
      <address type='pci' domain='0x0000' bus='0x05' slot='0x00' function='0x0'/>
    </memballoon>
    <rng model='virtio'>
      <backend model='random'>/dev/urandom</backend>
      <address type='pci' domain='0x0000' bus='0x06' slot='0x00' function='0x0'/>
    </rng>
  </devices>
</domain>
XML;
    
    return $xml;
}

/**
 * Build network interface XML
 */
function buildNetworkXml(string $bridge, string $mac, ?int $vlanTag = null): string {
    if ($vlanTag) {
        return <<<XML
    <interface type='bridge'>
      <mac address='$mac'/>
      <source bridge='$bridge'/>
      <vlan>
        <tag id='$vlanTag'/>
      </vlan>
      <model type='virtio'/>
      <address type='pci' domain='0x0000' bus='0x01' slot='0x00' function='0x0'/>
    </interface>
XML;
    } else {
        return <<<XML
    <interface type='bridge'>
      <mac address='$mac'/>
      <source bridge='$bridge'/>
      <model type='virtio'/>
      <address type='pci' domain='0x0000' bus='0x01' slot='0x00' function='0x0'/>
    </interface>
XML;
    }
}

/**
 * KVM action helper
 */
function kvmAction(string $action, string $name): array {
    [$code, $out, $err] = Shell::runf('virsh', [$action, $name]);
    return [$code === 0, $code === 0 ? "VM $action successful" : $err];
}

/**
 * Delete KVM VM
 */
function deleteKVM(string $name): array {
    // Stop VM if running
    Shell::runf('virsh', ['destroy', $name]);
    
    // Undefine VM
    [$code, $out, $err] = Shell::runf('virsh', ['undefine', $name, '--remove-all-storage']);
    
    // Clean up directory
    $vmDir = "/var/lib/vmforge/vms/$name";
    if (is_dir($vmDir)) {
        Shell::runf('rm', ['-rf', $vmDir]);
    }
    
    return [$code === 0, $code === 0 ? "VM deleted" : $err];
}

/**
 * Open VNC console
 */
function openConsole(array $payload): array {
    $name = $payload['name'] ?? '';
    $port = (int)($payload['listen_port'] ?? 5900);
    
    if (empty($name)) {
        return [false, "VM name required"];
    }
    
    // Get current VNC port
    [$code, $out, $err] = Shell::runf('virsh', ['vncdisplay', $name]);
    if ($code !== 0) {
        return [false, "Failed to get VNC display: $err"];
    }
    
    // Extract port number (format is :N where port = 5900 + N)
    if (preg_match('/^:(\d+)/', trim($out), $matches)) {
        $currentPort = 5900 + (int)$matches[1];
        
        // Set up port forwarding if needed
        if ($port !== $currentPort) {
            // Use socat or iptables for port forwarding
            $cmd = sprintf(
                'socat TCP-LISTEN:%d,fork,reuseaddr TCP:127.0.0.1:%d &',
                $port,
                $currentPort
            );
            Shell::run($cmd);
        }
        
        return [true, "Console available on port $port"];
    }
    
    return [false, "Failed to parse VNC display"];
}

/**
 * Close VNC console
 */
function closeConsole(array $payload): array {
    $port = (int)($payload['listen_port'] ?? 0);
    
    if ($port > 0) {
        // Kill socat process if running
        Shell::runf('pkill', ['-f', "socat.*:$port"]);
    }
    
    return [true, "Console closed"];
}

/**
 * Reinstall KVM from ISO
 */
function reinstallKVM(array $payload): array {
    $name = $payload['name'] ?? '';
    $isoId = (int)($payload['iso_id'] ?? 0);
    
    if (empty($name) || $isoId < 1) {
        return [false, "VM name and ISO ID required"];
    }
    
    // Get ISO path
    $isoPath = ISOStore::ensureLocal($isoId);
    if (!$isoPath || !is_file($isoPath)) {
        return [false, "ISO not available"];
    }
    
    // Stop VM
    Shell::runf('virsh', ['destroy', $name]);
    
    // Get VM XML
    [$code, $xml, $err] = Shell::runf('virsh', ['dumpxml', $name]);
    if ($code !== 0) {
        return [false, "Failed to get VM configuration: $err"];
    }
    
    // Modify XML to add/update CD-ROM with ISO
    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $xpath = new DOMXPath($dom);
    
    // Remove existing CD-ROM
    $cdroms = $xpath->query("//disk[@device='cdrom']");
    foreach ($cdroms as $cdrom) {
        $cdrom->parentNode->removeChild($cdrom);
    }
    
    // Add new CD-ROM
    $devices = $xpath->query("//devices")->item(0);
    $cdrom = $dom->createElement('disk');
    $cdrom->setAttribute('type', 'file');
    $cdrom->setAttribute('device', 'cdrom');
    
    $driver = $dom->createElement('driver');
    $driver->setAttribute('name', 'qemu');
    $driver->setAttribute('type', 'raw');
    $cdrom->appendChild($driver);
    
    $source = $dom->createElement('source');
    $source->setAttribute('file', $isoPath);
    $cdrom->appendChild($source);
    
    $target = $dom->createElement('target');
    $target->setAttribute('dev', 'sda');
    $target->setAttribute('bus', 'sata');
    $cdrom->appendChild($target);
    
    $cdrom->appendChild($dom->createElement('readonly'));
    $devices->appendChild($cdrom);
    
    // Change boot order to CD-ROM first
    $os = $xpath->query("//os")->item(0);
    $boots = $xpath->query("//os/boot");
    foreach ($boots as $boot) {
        $boot->parentNode->removeChild($boot);
    }
    
    $bootCd = $dom->createElement('boot');
    $bootCd->setAttribute('dev', 'cdrom');
    $os->appendChild($bootCd);
    
    $bootHd = $dom->createElement('boot');
    $bootHd->setAttribute('dev', 'hd');
    $os->appendChild($bootHd);
    
    // Save and redefine VM
    $xmlFile = "/tmp/vm-$name-reinstall.xml";
    $dom->save($xmlFile);
    
    [$code, $out, $err] = Shell::runf('virsh', ['define', $xmlFile]);
    unlink($xmlFile);
    
    if ($code !== 0) {
        return [false, "Failed to update VM configuration: $err"];
    }
    
    // Start VM
    [$code, $out, $err] = Shell::runf('virsh', ['start', $name]);
    if ($code !== 0) {
        return [false, "Failed to start VM: $err"];
    }
    
    return [true, "VM reinstall started with ISO attached"];
}

/**
 * Create LXC container
 */
function createLXC(array $payload, string $defaultBridge): array {
    $name = $payload['name'];
    $template = $payload['template'] ?? 'debian';
    $release = $payload['release'] ?? 'bullseye';
    $arch = $payload['arch'] ?? 'amd64';
    $bridge = $payload['bridge'] ?? $defaultBridge;
    $vcpus = (int)($payload['vcpus'] ?? 1);
    $memory = (int)($payload['memory_mb'] ?? 512);
    $diskSize = (int)($payload['disk_gb'] ?? 10);
    
    // Create container
    [$code, $out, $err] = Shell::runf('lxc-create', [
        '-n', $name,
        '-t', 'download',
        '--',
        '-d', $template,
        '-r', $release,
        '-a', $arch
    ]);
    
    if ($code !== 0) {
        return [false, "Failed to create container: $err"];
    }
    
    // Configure container
    $configFile = "/var/lib/lxc/$name/config";
    $config = file_get_contents($configFile);
    
    // CPU and memory limits
    $config .= "\n# Resource limits\n";
    $config .= "lxc.cgroup2.cpuset.cpus = 0-" . ($vcpus - 1) . "\n";
    $config .= "lxc.cgroup2.memory.max = " . ($memory * 1024 * 1024) . "\n";
    
    // Network configuration
    $config .= "\n# Network configuration\n";
    $config .= "lxc.net.0.type = veth\n";
    $config .= "lxc.net.0.link = $bridge\n";
    $config .= "lxc.net.0.flags = up\n";
    
    if (!empty($payload['mac_address'])) {
        $config .= "lxc.net.0.hwaddr = {$payload['mac_address']}\n";
    }
    
    if (!empty($payload['ip_address'])) {
        $config .= "lxc.net.0.ipv4.address = {$payload['ip_address']}/24\n";
        if (!empty($payload['gateway'])) {
            $config .= "lxc.net.0.ipv4.gateway = {$payload['gateway']}\n";
        }
    }
    
    if (!empty($payload['vlan_tag'])) {
        $config .= "lxc.net.0.vlan.id = {$payload['vlan_tag']}\n";
    }
    
    // Security
    $config .= "\n# Security\n";
    $config .= "lxc.apparmor.profile = generated\n";
    $config .= "lxc.apparmor.allow_nesting = 1\n";
    $config .= "lxc.seccomp.profile = /usr/share/lxc/config/common.seccomp\n";
    
    file_put_contents($configFile, $config);
    
    // Configure root filesystem size
    if ($diskSize > 0) {
        $rootfs = "/var/lib/lxc/$name/rootfs";
        if (is_dir($rootfs)) {
            // If using ZFS
            if (file_exists('/usr/sbin/zfs')) {
                Shell::runf('zfs', ['create', '-o', "quota={$diskSize}G", "rpool/lxc/$name"]);
            }
            // If using LVM
            else if (file_exists('/usr/sbin/lvcreate')) {
                Shell::runf('lvcreate', ['-L', "{$diskSize}G", '-n', $name, 'vg0']);
            }
        }
    }
    
    // Set root password if provided
    if (!empty($payload['password'])) {
        $hashedPassword = password_hash($payload['password'], PASSWORD_DEFAULT);
        Shell::runf('chroot', ["/var/lib/lxc/$name/rootfs", 'usermod', '-p', $hashedPassword, 'root']);
    }
    
    // Add SSH key if provided
    if (!empty($payload['ssh_key'])) {
        $sshDir = "/var/lib/lxc/$name/rootfs/root/.ssh";
        if (!is_dir($sshDir)) {
            mkdir($sshDir, 0700, true);
        }
        file_put_contents("$sshDir/authorized_keys", $payload['ssh_key'] . "\n");
        chmod("$sshDir/authorized_keys", 0600);
    }
    
    // Start container
    [$code, $out, $err] = Shell::runf('lxc-start', ['-n', $name, '-d']);
    
    return [$code === 0, $code === 0 ? "Container created and started" : $err];
}

/**
 * LXC action helper
 */
function lxcAction(string $action, string $name): array {
    [$code, $out, $err] = Shell::runf("lxc-$action", ['-n', $name]);
    return [$code === 0, $code === 0 ? "Container $action successful" : $err];
}

/**
 * Delete LXC container
 */
function deleteLXC(string $name): array {
    // Stop container if running
    Shell::runf('lxc-stop', ['-n', $name, '-k']);
    
    // Destroy container
    [$code, $out, $err] = Shell::runf('lxc-destroy', ['-n', $name]);
    
    // Clean up ZFS dataset if exists
    if (file_exists('/usr/sbin/zfs')) {
        Shell::runf('zfs', ['destroy', '-r', "rpool/lxc/$name"]);
    }
    
    // Clean up LVM volume if exists
    if (file_exists('/usr/sbin/lvremove')) {
        Shell::runf('lvremove', ['-f', "vg0/$name"]);
    }
    
    return [$code === 0, $code === 0 ? "Container deleted" : $err];
}

/**
 * Setup network bridge
 */
function setupNetwork(array $payload, string $defaultBridge): array {
    $bridge = $payload['bridge'] ?? $defaultBridge;
    $interface = $payload['interface'] ?? 'eth0';
    $vlan = $payload['vlan'] ?? null;
    
    // Check if bridge exists
    [$code, $out, $err] = Shell::runf('ip', ['link', 'show', $bridge]);
    if ($code === 0) {
        return [true, "Bridge $bridge already exists"];
    }
    
    // Create bridge
    [$code, $out, $err] = Shell::runf('ip', ['link', 'add', 'name', $bridge, 'type', 'bridge']);
    if ($code !== 0) {
        return [false, "Failed to create bridge: $err"];
    }
    
    // Enable STP
    Shell::runf('ip', ['link', 'set', $bridge, 'type', 'bridge', 'stp_state', '1']);
    
    // Add interface to bridge
    if ($vlan) {
        // Create VLAN interface
        $vlanIf = "$interface.$vlan";
        Shell::runf('ip', ['link', 'add', 'link', $interface, 'name', $vlanIf, 'type', 'vlan', 'id', (string)$vlan]);
        Shell::runf('ip', ['link', 'set', $vlanIf, 'master', $bridge]);
        Shell::runf('ip', ['link', 'set', $vlanIf, 'up']);
    } else {
        Shell::runf('ip', ['link', 'set', $interface, 'master', $bridge]);
    }
    
    // Bring up bridge
    Shell::runf('ip', ['link', 'set', $bridge, 'up']);
    
    // Configure bridge for VM networking
    Shell::runf('sysctl', ['-w', 'net.ipv4.ip_forward=1']);
    Shell::runf('sysctl', ['-w', 'net.ipv6.conf.all.forwarding=1']);
    
    // Persist configuration
    $netplanConfig = <<<YAML
network:
  version: 2
  ethernets:
    $interface:
      dhcp4: no
  bridges:
    $bridge:
      interfaces: [$interface]
      dhcp4: yes
      stp: true
      forward-delay: 0
YAML;
    
    file_put_contents("/etc/netplan/50-vmforge-$bridge.yaml", $netplanConfig);
    Shell::runf('netplan', ['apply']);
    
    return [true, "Bridge $bridge configured"];
}

/**
 * Setup anti-spoofing rules
 */
function setupAntispoof(array $payload): array {
    $name = $payload['name'] ?? '';
    $ip = $payload['ip4'] ?? '';
    $mac = $payload['mac'] ?? '';
    
    if (empty($name)) {
        return [false, "VM name required"];
    }
    
    // Get VM interface
    [$code, $out, $err] = Shell::runf('virsh', ['domiflist', $name]);
    if ($code !== 0) {
        return [false, "Failed to get VM interfaces: $err"];
    }
    
    $interface = null;
    foreach (explode("\n", $out) as $line) {
        if (preg_match('/^(vnet\d+|tap\d+)/', trim($line), $matches)) {
            $interface = $matches[1];
            break;
        }
    }
    
    if (!$interface) {
        return [false, "No interface found for VM"];
    }
    
    // Create nftables rules
    $rules = [];
    
    if ($mac) {
        $rules[] = "add rule inet filter forward iifname $interface ether saddr != $mac drop";
    }
    
    if ($ip) {
        $rules[] = "add rule inet filter forward iifname $interface ip saddr != $ip drop";
    }
    
    if (empty($rules)) {
        return [true, "No anti-spoofing rules to apply"];
    }
    
    // Apply rules
    foreach ($rules as $rule) {
        Shell::runf('nft', explode(' ', $rule));
    }
    
    // Save rules
    Shell::runf('nft', ['list', 'ruleset', '>', '/etc/nftables.conf']);
    
    return [true, "Anti-spoofing rules applied for $interface"];
}

/**
 * Setup gateway route
 */
function setupGateway(array $payload): array {
    $bridge = $payload['bridge'] ?? 'br0';
    $gateway = $payload['gateway'] ?? '';
    
    if (empty($gateway)) {
        return [false, "Gateway IP required"];
    }
    
    // Add IP to bridge if not exists
    [$code, $out, $err] = Shell::runf('ip', ['addr', 'show', $bridge]);
    if ($code !== 0) {
        return [false, "Bridge $bridge not found"];
    }
    
    if (strpos($out, $gateway) === false) {
        // Determine subnet (assume /24 for simplicity)
        $parts = explode('.', $gateway);
        $subnet = implode('.', array_slice($parts, 0, 3)) . '.0/24';
        
        [$code, $out, $err] = Shell::runf('ip', ['addr', 'add', "$gateway/24", 'dev', $bridge]);
        if ($code !== 0 && strpos($err, 'File exists') === false) {
            return [false, "Failed to add gateway IP: $err"];
        }
    }
    
    // Enable routing
    Shell::runf('sysctl', ['-w', 'net.ipv4.ip_forward=1']);
    
    // Add NAT rule for outbound traffic
    Shell::runf('nft', ['add', 'table', 'ip', 'nat']);
    Shell::runf('nft', ['add', 'chain', 'ip', 'nat', 'postrouting', '{', 'type', 'nat', 'hook', 'postrouting', 'priority', '100', ';', '}']);
    Shell::runf('nft', ['add', 'rule', 'ip', 'nat', 'postrouting', 'oifname', '!=', $bridge, 'masquerade']);
    
    return [true, "Gateway $gateway configured on $bridge"];
}

/**
 * Setup IPv6 router advertisements
 */
function setupIPv6RA(array $payload): array {
    $bridge = $payload['bridge'] ?? 'br0';
    $prefix = $payload['prefix'] ?? '';
    $gateway = $payload['gateway'] ?? '';
    
    if (empty($prefix)) {
        return [false, "IPv6 prefix required"];
    }
    
    // Configure radvd
    $radvdConfig = <<<CONFIG
interface $bridge {
    AdvSendAdvert on;
    MinRtrAdvInterval 3;
    MaxRtrAdvInterval 10;
    AdvDefaultPreference high;
    AdvHomeAgentFlag off;
    
    prefix $prefix {
        AdvOnLink on;
        AdvAutonomous on;
        AdvRouterAddr on;
    };
    
    RDNSS 2001:4860:4860::8888 2001:4860:4860::8844 {
        AdvRDNSSLifetime 30;
    };
};
CONFIG;
    
    file_put_contents('/etc/radvd.conf', $radvdConfig);
    
    // Add IPv6 address to bridge if gateway specified
    if ($gateway) {
        Shell::runf('ip', ['-6', 'addr', 'add', "$gateway/64", 'dev', $bridge]);
    }
    
    // Enable IPv6 forwarding
    Shell::runf('sysctl', ['-w', 'net.ipv6.conf.all.forwarding=1']);
    
    // Restart radvd
    Shell::runf('systemctl', ['restart', 'radvd']);
    
    return [true, "IPv6 RA configured for $prefix"];
}

/**
 * Create snapshot
 */
function createSnapshot(array $payload): array {
    $name = $payload['name'] ?? '';
    $snapshot = $payload['snapshot'] ?? date('Ymd-His');
    
    if (empty($name)) {
        return [false, "VM name required"];
    }
    
    // Check VM type
    [$code, $out, $err] = Shell::runf('virsh', ['dominfo', $name]);
    if ($code === 0) {
        // KVM snapshot
        [$code, $out, $err] = Shell::runf('virsh', ['snapshot-create-as', $name, $snapshot, '--atomic']);
        return [$code === 0, $code === 0 ? "Snapshot created: $snapshot" : $err];
    }
    
    // Try LXC
    if (file_exists("/var/lib/lxc/$name")) {
        // LXC snapshot using ZFS or LVM
        if (file_exists('/usr/sbin/zfs')) {
            $dataset = "rpool/lxc/$name";
            [$code, $out, $err] = Shell::runf('zfs', ['snapshot', "$dataset@$snapshot"]);
        } else if (file_exists('/usr/sbin/lvcreate')) {
            [$code, $out, $err] = Shell::runf('lvcreate', ['-s', '-n', "$name-$snapshot", '-L', '1G', "vg0/$name"]);
        } else {
            return [false, "No snapshot support available"];
        }
        
        return [$code === 0, $code === 0 ? "Snapshot created: $snapshot" : $err];
    }
    
    return [false, "VM not found"];
}

/**
 * Delete snapshot
 */
function deleteSnapshot(array $payload): array {
    $name = $payload['name'] ?? '';
    $snapshot = $payload['snapshot'] ?? '';
    
    if (empty($name) || empty($snapshot)) {
        return [false, "VM name and snapshot name required"];
    }
    
    // Try KVM
    [$code, $out, $err] = Shell::runf('virsh', ['snapshot-delete', $name, $snapshot]);
    if ($code === 0) {
        return [true, "Snapshot deleted"];
    }
    
    // Try ZFS
    if (file_exists('/usr/sbin/zfs')) {
        $dataset = "rpool/lxc/$name@$snapshot";
        [$code, $out, $err] = Shell::runf('zfs', ['destroy', $dataset]);
        if ($code === 0) {
            return [true, "Snapshot deleted"];
        }
    }
    
    // Try LVM
    if (file_exists('/usr/sbin/lvremove')) {
        [$code, $out, $err] = Shell::runf('lvremove', ['-f', "vg0/$name-$snapshot"]);
        if ($code === 0) {
            return [true, "Snapshot deleted"];
        }
    }
    
    return [false, "Failed to delete snapshot"];
}

/**
 * Restore snapshot
 */
function restoreSnapshot(array $payload): array {
    $name = $payload['name'] ?? '';
    $snapshot = $payload['snapshot'] ?? '';
    
    if (empty($name) || empty($snapshot)) {
        return [false, "VM name and snapshot name required"];
    }
    
    // Try KVM
    [$code, $out, $err] = Shell::runf('virsh', ['snapshot-revert', $name, $snapshot]);
    if ($code === 0) {
        return [true, "Snapshot restored"];
    }
    
    // Try ZFS
    if (file_exists('/usr/sbin/zfs')) {
        $dataset = "rpool/lxc/$name";
        [$code, $out, $err] = Shell::runf('zfs', ['rollback', "$dataset@$snapshot"]);
        if ($code === 0) {
            return [true, "Snapshot restored"];
        }
    }
    
    return [false, "Failed to restore snapshot"];
}

/**
 * Create backup
 */
function createBackup(array $payload): array {
    $name = $payload['name'] ?? '';
    $uuid = $payload['uuid'] ?? '';
    $backupDir = '/var/lib/vmforge/backups';
    
    if (empty($name)) {
        return [false, "VM name required"];
    }
    
    // Create backup directory
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    $timestamp = date('Ymd-His');
    $backupName = "$name-$timestamp";
    $backupPath = "$backupDir/$backupName";
    
    // Check if KVM
    [$code, $out, $err] = Shell::runf('virsh', ['dominfo', $name]);
    if ($code === 0) {
        // Backup KVM
        // Create snapshot
        Shell::runf('virsh', ['snapshot-create-as', $name, "$backupName-snap", '--atomic']);
        
        // Get disk paths
        [$code, $out, $err] = Shell::runf('virsh', ['domblklist', $name]);
        $disks = [];
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^vd[a-z]\s+(.+)$/', trim($line), $matches)) {
                $disks[] = $matches[1];
            }
        }
        
        // Dump XML
        [$code, $xml, $err] = Shell::runf('virsh', ['dumpxml', $name]);
        file_put_contents("$backupPath.xml", $xml);
        
        // Backup disks
        foreach ($disks as $disk) {
            $diskName = basename($disk);
            Shell::runf('qemu-img', ['convert', '-O', 'qcow2', '-c', $disk, "$backupPath-$diskName"]);
        }
        
        // Delete snapshot
        Shell::runf('virsh', ['snapshot-delete', $name, "$backupName-snap"]);
        
        // Create tarball
        Shell::runf('tar', ['-czf', "$backupPath.tar.gz", '-C', dirname($backupPath), basename($backupPath) . '*']);
        
        // Clean up
        unlink("$backupPath.xml");
        foreach ($disks as $disk) {
            $diskName = basename($disk);
            unlink("$backupPath-$diskName");
        }
        
        // Calculate checksum
        $checksum = hash_file('sha256', "$backupPath.tar.gz");
        $size = filesize("$backupPath.tar.gz");
        
        // Update database
        if ($uuid) {
            $pdo = DB::pdo();
            $stmt = $pdo->prepare('
                INSERT INTO backups (vm_uuid, snapshot_name, location, size_bytes, checksum_sha256, status)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$uuid, $backupName, "$backupPath.tar.gz", $size, $checksum, 'ready']);
        }
        
        return [true, "Backup created: $backupPath.tar.gz"];
    }
    
    // Try LXC
    if (file_exists("/var/lib/lxc/$name")) {
        // Stop container
        Shell::runf('lxc-stop', ['-n', $name]);
        
        // Create tarball
        [$code, $out, $err] = Shell::runf('tar', [
            '-czf', "$backupPath.tar.gz",
            '-C', '/var/lib/lxc',
            $name
        ]);
        
        // Start container
        Shell::runf('lxc-start', ['-n', $name, '-d']);
        
        if ($code === 0) {
            $checksum = hash_file('sha256', "$backupPath.tar.gz");
            $size = filesize("$backupPath.tar.gz");
            
            // Update database
            if ($uuid) {
                $pdo = DB::pdo();
                $stmt = $pdo->prepare('
                    INSERT INTO backups (vm_uuid, snapshot_name, location, size_bytes, checksum_sha256, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$uuid, $backupName, "$backupPath.tar.gz", $size, $checksum, 'ready']);
            }
            
            return [true, "Backup created: $backupPath.tar.gz"];
        }
        
        return [false, "Backup failed: $err"];
    }
    
    return [false, "VM not found"];
}

/**
 * Restore backup
 */
function restoreBackup(array $payload): array {
    $backupPath = $payload['source'] ?? '';
    $name = $payload['vm_name'] ?? '';
    
    if (empty($backupPath) || empty($name)) {
        return [false, "Backup path and VM name required"];
    }
    
    if (!file_exists($backupPath)) {
        return [false, "Backup file not found"];
    }
    
    // Extract backup
    $tempDir = "/tmp/restore-" . uniqid();
    mkdir($tempDir);
    
    [$code, $out, $err] = Shell::runf('tar', ['-xzf', $backupPath, '-C', $tempDir]);
    if ($code !== 0) {
        return [false, "Failed to extract backup: $err"];
    }
    
    // Check if KVM backup (has XML file)
    $xmlFiles = glob("$tempDir/*.xml");
    if (!empty($xmlFiles)) {
        // Restore KVM
        $xmlFile = $xmlFiles[0];
        
        // Stop and undefine existing VM if exists
        Shell::runf('virsh', ['destroy', $name]);
        Shell::runf('virsh', ['undefine', $name]);
        
        // Restore disks
        $vmDir = "/var/lib/vmforge/vms/$name";
        if (!is_dir($vmDir)) {
            mkdir($vmDir, 0755, true);
        }
        
        $disks = glob("$tempDir/*.qcow2");
        foreach ($disks as $disk) {
            copy($disk, "$vmDir/" . basename($disk));
        }
        
        // Update XML with new paths
        $xml = file_get_contents($xmlFile);
        $xml = preg_replace('/\/var\/lib\/vmforge\/vms\/[^\/]+\//', "$vmDir/", $xml);
        file_put_contents($xmlFile, $xml);
        
        // Define VM
        [$code, $out, $err] = Shell::runf('virsh', ['define', $xmlFile]);
        if ($code !== 0) {
            return [false, "Failed to define VM: $err"];
        }
        
        // Start VM
        Shell::runf('virsh', ['start', $name]);
    } else {
        // Restore LXC
        // Stop and destroy existing container if exists
        Shell::runf('lxc-stop', ['-n', $name, '-k']);
        Shell::runf('lxc-destroy', ['-n', $name]);
        
        // Restore container
        [$code, $out, $err] = Shell::runf('tar', [
            '-xzf', $backupPath,
            '-C', '/var/lib/lxc'
        ]);
        
        if ($code !== 0) {
            return [false, "Failed to restore container: $err"];
        }
        
        // Start container
        Shell::runf('lxc-start', ['-n', $name, '-d']);
    }
    
    // Clean up temp directory
    Shell::runf('rm', ['-rf', $tempDir]);
    
    return [true, "Backup restored successfully"];
}

/**
 * Resize disk
 */
function resizeDisk(array $payload): array {
    $name = $payload['name'] ?? '';
    $newSize = (int)($payload['size_gb'] ?? 0);
    
    if (empty($name) || $newSize < 1) {
        return [false, "VM name and new size required"];
    }
    
    // Get VM disk path
    [$code, $out, $err] = Shell::runf('virsh', ['domblklist', $name]);
    if ($code !== 0) {
        return [false, "Failed to get VM disks: $err"];
    }
    
    $diskPath = null;
    foreach (explode("\n", $out) as $line) {
        if (preg_match('/^vda\s+(.+)$/', trim($line), $matches)) {
            $diskPath = $matches[1];
            break;
        }
    }
    
    if (!$diskPath) {
        return [false, "No disk found for VM"];
    }
    
    // Resize disk image
    [$code, $out, $err] = Shell::runf('qemu-img', ['resize', $diskPath, "{$newSize}G"]);
    if ($code !== 0) {
        return [false, "Failed to resize disk: $err"];
    }
    
    // If VM is running, notify guest
    [$code, $out, $err] = Shell::runf('virsh', ['domstate', $name]);
    if ($code === 0 && trim($out) === 'running') {
        Shell::runf('virsh', ['blockresize', $name, $diskPath, "{$newSize}G"]);
    }
    
    return [true, "Disk resized to {$newSize}GB"];
}

/**
 * Sync firewall rules
 */
function syncFirewall(array $payload): array {
    $uuid = $payload['uuid'] ?? '';
    $name = $payload['name'] ?? '';
    
    if (empty($uuid) || empty($name)) {
        return [false, "VM UUID and name required"];
    }
    
    // Get VM interface
    [$code, $out, $err] = Shell::runf('virsh', ['domiflist', $name]);
    if ($code !== 0) {
        return [false, "Failed to get VM interfaces: $err"];
    }
    
    $interface = null;
    foreach (explode("\n", $out) as $line) {
        if (preg_match('/^(vnet\d+|tap\d+)/', trim($line), $matches)) {
            $interface = $matches[1];
            break;
        }
    }
    
    if (!$interface) {
        return [false, "No interface found for VM"];
    }
    
    // Get firewall rules from database
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('
        SELECT * FROM firewall_rules 
        WHERE vm_uuid = ? AND enabled = 1 
        ORDER BY priority ASC
    ');
    $stmt->execute([$uuid]);
    $rules = $stmt->fetchAll();
    
    // Get firewall mode
    $stmt = $pdo->prepare('SELECT firewall_mode FROM vm_instances WHERE uuid = ?');
    $stmt->execute([$uuid]);
    $mode = $stmt->fetchColumn() ?: 'disabled';
    
    // Create nftables chain for VM
    $chain = "vm-$interface";
    
    // Delete existing chain
    Shell::runf('nft', ['delete', 'chain', 'inet', 'filter', $chain]);
    
    // Create new chain
    Shell::runf('nft', ['add', 'chain', 'inet', 'filter', $chain]);
    
    if ($mode === 'disabled') {
        // Allow all traffic
        Shell::runf('nft', ['add', 'rule', 'inet', 'filter', $chain, 'accept']);
    } else {
        // Default policy based on mode
        $defaultAction = $mode === 'allowlist' ? 'drop' : 'accept';
        
        // Add rules
        foreach ($rules as $rule) {
            $nftRule = "add rule inet filter $chain ";
            
            // Protocol
            if ($rule['protocol'] !== 'any') {
                $nftRule .= $rule['protocol'] . ' ';
            }
            
            // Source
            if (!empty($rule['source_cidr']) && $rule['source_cidr'] !== 'any') {
                $nftRule .= 'ip saddr ' . $rule['source_cidr'] . ' ';
            }
            
            // Destination ports
            if (!empty($rule['dest_ports']) && $rule['dest_ports'] !== 'any') {
                if (strpos($rule['dest_ports'], '-') !== false) {
                    // Port range
                    $nftRule .= 'dport ' . $rule['dest_ports'] . ' ';
                } else if (strpos($rule['dest_ports'], ',') !== false) {
                    // Multiple ports
                    $nftRule .= 'dport { ' . $rule['dest_ports'] . ' } ';
                } else {
                    // Single port
                    $nftRule .= 'dport ' . $rule['dest_ports'] . ' ';
                }
            }
            
            // Action
            $nftRule .= $rule['action'] === 'allow' ? 'accept' : 'drop';
            
            Shell::run("nft $nftRule");
        }
        
        // Add default rule
        Shell::runf('nft', ['add', 'rule', 'inet', 'filter', $chain, $defaultAction]);
    }
    
    // Add jump rule in forward chain
    Shell::runf('nft', ['add', 'rule', 'inet', 'filter', 'forward', 'iifname', $interface, 'jump', $chain]);
    
    // Save rules
    Shell::run('nft list ruleset > /etc/nftables.conf');
    
    return [true, "Firewall rules synced for $interface"];
}

/**
 * ZFS backup
 */
function zfsBackup(array $payload): array {
    $name = $payload['name'] ?? '';
    $uuid = $payload['uuid'] ?? '';
    $repoId = (int)($payload['repo_id'] ?? 0);
    
    if (empty($name) || $repoId < 1) {
        return [false, "VM name and repository ID required"];
    }
    
    // Get repository configuration
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM zfs_repos WHERE id = ?');
    $stmt->execute([$repoId]);
    $repo = $stmt->fetch();
    
    if (!$repo) {
        return [false, "Repository not found"];
    }
    
    // Create snapshot
    $timestamp = date('Ymd-His');
    $snapshot = "backup-$timestamp";
    $dataset = "rpool/vmforge/$name";
    
    [$code, $out, $err] = Shell::runf('zfs', ['snapshot', "$dataset@$snapshot"]);
    if ($code !== 0) {
        return [false, "Failed to create snapshot: $err"];
    }
    
    // Send to repository
    $destDataset = $repo['dataset'] . "/$name";
    
    if ($repo['mode'] === 'ssh') {
        // Remote backup
        $user = $repo['remote_user'] ?: 'root';
        $host = $repo['remote_host'];
        $port = $repo['ssh_port'] ?: 22;
        
        $cmd = sprintf(
            'zfs send %s | ssh -p %d %s@%s zfs recv -F %s',
            escapeshellarg("$dataset@$snapshot"),
            $port,
            $user,
            $host,
            escapeshellarg("$destDataset@$snapshot")
        );
    } else {
        // Local backup
        $cmd = sprintf(
            'zfs send %s | zfs recv -F %s',
            escapeshellarg("$dataset@$snapshot"),
            escapeshellarg("$destDataset@$snapshot")
        );
    }
    
    [$code, $out, $err] = Shell::run($cmd);
    if ($code !== 0) {
        // Clean up snapshot
        Shell::runf('zfs', ['destroy', "$dataset@$snapshot"]);
        return [false, "Failed to send snapshot: $err"];
    }
    
    // Record backup in database
    if ($uuid) {
        $location = "$destDataset@$snapshot";
        $stmt = $pdo->prepare('
            INSERT INTO backups (vm_uuid, snapshot_name, location, type, size_bytes, checksum_sha256, storage, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $uuid,
            $snapshot,
            $location,
            'full',
            0, // Size would need to be calculated
            '', // Checksum would need to be calculated
            'zfs',
            'ready'
        ]);
    }
    
    return [true, "ZFS backup completed: $snapshot"];
}

/**
 * ZFS restore
 */
function zfsRestore(array $payload): array {
    $name = $payload['name'] ?? '';
    $source = $payload['source'] ?? '';
    
    if (empty($name) || empty($source)) {
        return [false, "VM name and source required"];
    }
    
    // Parse source (format: dataset@snapshot)
    if (strpos($source, '@') === false) {
        return [false, "Invalid source format"];
    }
    
    [$sourceDataset, $snapshot] = explode('@', $source, 2);
    $destDataset = "rpool/vmforge/$name";
    
    // Stop VM if running
    Shell::runf('virsh', ['destroy', $name]);
    
    // Destroy existing dataset
    Shell::runf('zfs', ['destroy', '-r', $destDataset]);
    
    // Restore from snapshot
    $cmd = sprintf(
        'zfs send %s | zfs recv -F %s',
        escapeshellarg($source),
        escapeshellarg($destDataset)
    );
    
    [$code, $out, $err] = Shell::run($cmd);
    if ($code !== 0) {
        return [false, "Failed to restore: $err"];
    }
    
    // Start VM
    Shell::runf('virsh', ['start', $name]);
    
    return [true, "ZFS restore completed"];
}

/**
 * ZFS prune old backups
 */
function zfsPrune(array $payload): array {
    $name = $payload['name'] ?? '';
    $repoId = (int)($payload['repo_id'] ?? 0);
    $keepLast = (int)($payload['keep_last'] ?? 7);
    
    if (empty($name) || $repoId < 1) {
        return [false, "VM name and repository ID required"];
    }
    
    // Get repository configuration
    $pdo = DB::pdo();
    $stmt = $pdo->prepare('SELECT * FROM zfs_repos WHERE id = ?');
    $stmt->execute([$repoId]);
    $repo = $stmt->fetch();
    
    if (!$repo) {
        return [false, "Repository not found"];
    }
    
    $dataset = $repo['dataset'] . "/$name";
    
    // List snapshots
    if ($repo['mode'] === 'ssh') {
        $user = $repo['remote_user'] ?: 'root';
        $host = $repo['remote_host'];
        $port = $repo['ssh_port'] ?: 22;
        
        $cmd = sprintf(
            'ssh -p %d %s@%s zfs list -t snapshot -o name -s creation %s',
            $port,
            $user,
            $host,
            escapeshellarg($dataset)
        );
    } else {
        $cmd = sprintf(
            'zfs list -t snapshot -o name -s creation %s',
            escapeshellarg($dataset)
        );
    }
    
    [$code, $out, $err] = Shell::run($cmd);
    if ($code !== 0) {
        return [false, "Failed to list snapshots: $err"];
    }
    
    $snapshots = array_filter(explode("\n", trim($out)));
    array_shift($snapshots); // Remove header
    
    // Keep only last N snapshots
    $toDelete = array_slice($snapshots, 0, -$keepLast);
    
    foreach ($toDelete as $snapshot) {
        if ($repo['mode'] === 'ssh') {
            $cmd = sprintf(
                'ssh -p %d %s@%s zfs destroy %s',
                $port,
                $user,
                $host,
                escapeshellarg($snapshot)
            );
        } else {
            $cmd = sprintf('zfs destroy %s', escapeshellarg($snapshot));
        }
        
        Shell::run($cmd);
    }
    
    $deleted = count($toDelete);
    return [true, "Pruned $deleted old snapshots, kept last $keepLast"];
}

/**
 * Helper function to generate MAC address
 */
function generateMac(): string {
    return sprintf(
        '52:54:00:%02x:%02x:%02x',
        random_int(0, 255),
        random_int(0, 255),
        random_int(0, 255)
    );
}
