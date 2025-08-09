<?php
namespace VMForge\Core;

class Shell {
    public static function run(string $cmd): array {
        // Guard against obvious shell metacharacters in raw strings.
        // This is NOT a sanitizer; callers should prefer runArgs().
        if (preg_match('~[;`|&<>\\]~', $cmd)) {
            // Allow redirection for our internal usage? No. Fail closed.
            return [127, '', 'unsafe shell metacharacters detected'];
        }
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $p = proc_open($cmd, $descriptors, $pipes);
        if (!\is_resource($p)) return [127, '', 'proc_open failed'];
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($p);
        return [$code, $out, $err];
    }

    /**
     * Execute a command with arguments safely. Each arg is escaped.
     * Example: runArgs(['virsh','start',$name]);
     */
    public static function runArgs(array $argv): array {
        $cmd = implode(' ', array_map('escapeshellarg', $argv));
        // First element is the binary; keep it unescaped if it has no spaces
        if (!empty($argv)) {
            $bin = $argv[0];
            if (preg_match('~^[a-zA-Z0-9_./-]+$~', $bin)) {
                $args = array_slice($argv, 1);
                $cmd = $bin . (count($args) ? ' ' . implode(' ', array_map('escapeshellarg', $args)) : '');
            }
        }
        return self::run($cmd);
    }

    /** Quick helper: run binary with formatted arguments (all escaped). */
    public static function runf(string $bin, array $args): array {
        array_unshift($args, $bin);
        return self::runArgs($args);
    }
}
