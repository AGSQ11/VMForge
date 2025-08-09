<?php
namespace VMForge\Controllers;

use VMForge\Core\DB;
use VMForge\Services\AgentToken;

class AgentController {
    /** POST /agent/poll  { token } */
    public function poll() {
        header('Content-Type: application/json');
        $token = $_POST['token'] ?? '';
        if ($token === '') { http_response_code(401); echo json_encode(['error'=>'missing token']); return; }

        $node = self::authenticateNode($token);
        if (!$node) { http_response_code(401); echo json_encode(['error'=>'unauthorized']); return; }

        // heartbeat
        $pdo = DB::pdo();
        $pdo->prepare('UPDATE nodes SET last_seen_at=NOW() WHERE id=?')->execute([$node['id']]);

        // fetch next pending job
        $st = $pdo->prepare('SELECT id, type, payload FROM jobs WHERE node_id=? AND status=\'pending\' ORDER BY id ASC LIMIT 1');
        $st->execute([$node['id']]);
        $job = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$job) { echo json_encode(['job'=>null]); return; }

        // mark in_progress
        $upd = $pdo->prepare('UPDATE jobs SET status=\'in_progress\', started_at=NOW() WHERE id=? AND status=\'pending\'');
        $upd->execute([$job['id']]);

        echo json_encode(['job'=> $job ]);
    }

    /** POST /agent/ack  { id, status, log } */
    public function ack() {
        header('Content-Type: application/json');
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $log = (string)($_POST['log'] ?? '');
        if ($id < 1 || ($status !== 'done' && $status !== 'failed')) { http_response_code(400); echo json_encode(['error'=>'bad input']); return; }

        $pdo = DB::pdo();
        $st = $pdo->prepare('UPDATE jobs SET status=?, finished_at=NOW(), log=? WHERE id=?');
        $st->execute([$status, $log, $id]);

        echo json_encode(['ok'=>true]);
    }

    /** Find node by token (hashed or legacy); migrate legacy token to hashed on success. */
    private static function authenticateNode(string $provided): ?array {
        $pdo = DB::pdo();

        // First pass: try legacy plaintext match
        $st = $pdo->prepare('SELECT id, token, token_hash, token_old_hash FROM nodes WHERE token = ? LIMIT 1');
        $st->execute([$provided]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            AgentToken::migrateIfLegacy((int)$row['id'], $provided, $row['token'], $row['token_hash']);
            return ['id' => (int)$row['id']];
        }

        // Second pass: verify against hashed tokens
        $q = $pdo->query('SELECT id, token_hash, token_old_hash FROM nodes WHERE token_hash IS NOT NULL OR token_old_hash IS NOT NULL');
        while ($r = $q->fetch(\PDO::FETCH_ASSOC)) {
            if (\VMForge\Services\AgentToken::verify($provided, $r['token_hash'] ?? null) || \VMForge\Services\AgentToken::verify($provided, $r['token_old_hash'] ?? null)) {
                return ['id' => (int)$r['id']];
            }
        }
        return null;
    }
}
