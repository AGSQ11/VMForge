<?php
namespace VMForge\Core;

class Shell {
    private const DANGEROUS_CHARS = ['&', '|', ';', '$', '`', '\\n', '\\r', '>', '<', '(', ')', '{', '}', '[', ']', '\\', '"', "'"];
    private const MAX_COMMAND_LENGTH = 8192;
    
    /**
     * Execute shell command safely with proper escaping
     */
    public static function run(string $command): array {
        // Validate command length
        if (strlen($command) > self::MAX_COMMAND_LENGTH) {
            return [1, '', 'Command too long'];
        }
        
        // Check for dangerous characters
        foreach (self::DANGEROUS_CHARS as $char) {
            if (strpos($command, $char) !== false) {
                // Log potential injection attempt
                error_log('Potential shell injection attempt: ' . $command);
                return [1, '', 'Invalid command characters detected'];
            }
        }
        
        return self::execute($command);
    }
    
    /**
     * Execute command with arguments safely escaped
     */
    public static function runArgs(array $args): array {
        if (empty($args)) {
            return [1, '', 'No command provided'];
        }
        
        $command = array_shift($args);
        
        // Validate command is in allowed list
        if (!self::isAllowedCommand($command)) {
            return [1, '', 'Command not allowed'];
        }
        
        // Escape all arguments
        $escapedArgs = array_map('escapeshellarg', $args);
        $fullCommand = escapeshellcmd($command) . ' ' . implode(' ', $escapedArgs);
        
        return self::execute($fullCommand);
    }
    
    /**
     * Execute command with format string (like sprintf but for shell)
     */
    public static function runf(string $command, array $args): array {
        // Escape all arguments
        $escapedArgs = array_map('escapeshellarg', $args);
        
        // Build command
        $fullCommand = escapeshellcmd($command) . ' ' . implode(' ', $escapedArgs);
        
        return self::execute($fullCommand);
    }
    
    /**
     * Execute command and return structured output
     */
    private static function execute(string $command): array {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w']   // stderr
        ];
        
        $process = proc_open($command, $descriptors, $pipes);
        
        if (!is_resource($process)) {
            return [1, '', 'Failed to execute command'];
        }
        
        // Close stdin
        fclose($pipes[0]);
        
        // Read output
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        $returnCode = proc_close($process);
        
        return [$returnCode, trim($stdout), trim($stderr)];
    }
    
    /**
     * Check if command is in allowed list
     */
    private static function isAllowedCommand(string $command): bool {
        $allowed = [
            'virsh', 'qemu-img', 'lxc-create', 'lxc-start', 'lxc-stop', 
            'lxc-destroy', 'lxc-info', 'lxc-ls', 'zfs', 'nft', 'ip',
            'brctl', 'ovs-vsctl', 'radvd', 'cloud-localds', 'genisoimage',
            'dd', 'mkfs', 'mount', 'umount', 'lvs', 'lvcreate', 'lvremove',
            'vgs', 'vgcreate', 'pvs', 'pvcreate', 'parted', 'rsync',
            'scp', 'ssh', 'tar', 'gzip', 'bzip2', 'xz'
        ];
        
        return in_array($command, $allowed, true);
    }
    
    /**
     * Execute command with timeout
     */
    public static function runWithTimeout(string $command, int $timeout = 30): array {
        $command = 'timeout ' . escapeshellarg((string)$timeout) . ' ' . $command;
        return self::execute($command);
    }
    
    /**
     * Execute command in background
     */
    public static function runBackground(string $command): bool {
        $command = $command . ' > /dev/null 2>&1 &';
        exec($command, $output, $returnCode);
        return $returnCode === 0;
    }
    
    /**
     * Check if process is running
     */
    public static function isProcessRunning(int $pid): bool {
        $command = 'ps -p ' . escapeshellarg((string)$pid);
        [$code, $output, $error] = self::execute($command);
        return $code === 0;
    }
    
    /**
     * Kill process by PID
     */
    public static function killProcess(int $pid, int $signal = 15): bool {
        $command = 'kill -' . escapeshellarg((string)$signal) . ' ' . escapeshellarg((string)$pid);
        [$code, $output, $error] = self::execute($command);
        return $code === 0;
    }
}
