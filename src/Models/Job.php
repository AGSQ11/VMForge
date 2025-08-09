<?php
namespace VMForge\Models;
use VMForge\Core\DB;
use PDO;
class Job {
    public static function enqueue(int $nodeId, string $type, array $payload): int {
        $st = DB::pdo()->prepare('INSERT INTO agent_jobs (node_id, type, payload, status) VALUES (?,?,?, "queued")');
        $st->execute([$nodeId, $type, json_encode($payload)]);
        return (int)DB::pdo()->lastInsertId();
    }
    public static function poll(int $nodeId): ?array {
        $st = DB::pdo()->prepare('SELECT * FROM agent_jobs WHERE node_id=? AND status="queued" ORDER BY id ASC LIMIT 1');
        $st->execute([$nodeId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            DB::pdo()->prepare('UPDATE agent_jobs SET status="running" WHERE id=?')->execute([$r['id']]);
        }
        return $r ?: null;
    }
    public static function ack(int $jobId, string $status, ?string $log): void {
        $st = DB::pdo()->prepare('UPDATE agent_jobs SET status=?, log=? WHERE id=?');
        $st->execute([$status, $log, $jobId]);
    }
}
