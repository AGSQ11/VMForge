<?php
namespace VMForge\Core;
class Shell {
    public static function run(string $cmd, ?array $env=null): array {
        $spec = [1=>['pipe','w'], 2=>['pipe','w']];
        $proc = proc_open($cmd, $spec, $pipes, null, $env ?? null);
        if (!is_resource($proc)) return [1, '', 'proc_open failed'];
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($proc);
        return [$code, $out, $err];
    }
}
