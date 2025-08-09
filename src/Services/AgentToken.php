<?php
namespace VMForge\Services;

use VMForge\Core\DB;

class AgentToken {
    public static function generate(): string {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string {
        return password_hash($token, PASSWORD_ARGON2ID, [
            'memory_cost' => 1<<16, // 65536
            'time_cost'   => 4,
            'threads'     => 3,
        ]);
    }

    public static function verify(string $token, ?string $hash): bool {
        return $hash ? password_verify($token, $hash) : false;
    }

    /**
     * If node has legacy plaintext token stored, upgrade to hashed transparently.
     * Returns true if an upgrade was performed.
     */
    public static function migrateIfLegacy(int $nodeId, string $providedToken, ?string $legacyToken, ?string $currentHash): bool {
        if ($currentHash) return false; // already hashed
        if ($legacyToken !== null && hash_equals($legacyToken, $providedToken)) {
            $hash = self::hash($providedToken);
            $pdo = DB::pdo();
            $st = $pdo->prepare('UPDATE nodes SET token_hash=?, token=NULL WHERE id=?');
            $st->execute([$hash, $nodeId]);
            return true;
        }
        return false;
    }

    /**
     * Rotate token: store old hash in token_old_hash for a 1h grace period.
     * Returns plaintext for display (only once).
     */
    public static function rotate(int $nodeId): string {
        $new = self::generate();
        $hash = self::hash($new);
        $pdo = DB::pdo();
        $st = $pdo->prepare('UPDATE nodes SET token_old_hash=token_hash, token_hash=?, token_rotated_at=NOW() WHERE id=?');
        $st->execute([$hash, $nodeId]);
        return $new;
    }

    /** Invalidate grace token if older than $seconds (default 3600). */
    public static function expireOldIfNeeded(int $seconds = 3600): void {
        $pdo = DB::pdo();
        $pdo->exec('UPDATE nodes SET token_old_hash=NULL WHERE token_old_hash IS NOT NULL AND token_rotated_at IS NOT NULL AND token_rotated_at < (NOW() - INTERVAL ' . (int)$seconds . ' SECOND)');
    }
}
