<?php
namespace VMForge\Core;
class Env {
    public static function load(string $path): void {
        if (!file_exists($path)) return;
        foreach (file($path) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            [$k,$v] = array_map('trim', explode('=', $line, 2));
            $v = trim($v, '\"\'');
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
    }
    public static function get(string $key, ?string $default=null): ?string {
        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
}
