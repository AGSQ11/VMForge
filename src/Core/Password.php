<?php
namespace VMForge\Core;

final class Password {
    public static function hash(string $password): string {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 1<<16, // 65536
            'time_cost'   => 4,
            'threads'     => 3,
        ]);
    }
    public static function verify(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }
    public static function needsRehash(string $hash): bool {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, [
            'memory_cost' => 1<<16,
            'time_cost'   => 4,
            'threads'     => 3,
        ]);
    }
}
