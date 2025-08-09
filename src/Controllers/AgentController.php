<?php
namespace VMForge\Controllers;

use VMForge\Core\DB;

class AgentController {
    public function poll() {
        header('Content-Type: application/json');
        $token = $_POST['token'] ?? '';
        if ($token === '') { http_response_code(401); echo json_encode(['error'=>'missing token']); return; }

        $nodeId = $this->verifyNodeToken($token);
        if ($nodeId === null) { http_response_code(401); echo json_encode(['error'=>'invalid token']); return; }

        $pdo = DB::pdo();
        // Find one pending job for this node
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT id, type, payload FROM jobs WHERE node_id=? AND status='pending' ORDER BY id ASC LIMIT 1 FOR UPDATE");
            $st->execute([$nodeId]);
            $job = $st->fetch(\PDO::FETCH_ASSOC);
            if ($job) {
                $upd = $pdo->prepare("UPDATE jobs SET status='running', started_at=NOW() WHERE id=? AND status='pending'");
                $upd->execute([$job['id']]);
            }
            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error'=>'db error']);
            return;
        }

        echo json_encode(['job' => $job ?: null]);
    }

    public function ack() {
        header('Content-Type: application/json');
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $status = $_POST['status'] ?? '';
        $log = $_POST['log'] ?? '';
        if ($id < 1 || ($status !== 'done' && $status !== 'failed')) { http_response_code(400); echo json_encode(['error'=>'bad params']); return; }

        $pdo = DB::pdo();
        $st = $pdo->prepare("UPDATE jobs SET status=?, log=?, finished_at=NOW() WHERE id=?");
        $st->execute([$status, substr((string)$log, 0, 65535), $id]);
        echo json_encode(['ok'=>true]);
    }

    private function verifyNodeToken(string $token): ?int {
        $pdo = DB::pdo();
        // Prefer hashed verification to avoid plaintext dependency
        $rows = $pdo->query("SELECT id, token_hash, token_old_hash, token FROM nodes")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (!empty($r['token_hash']) && password_verify($token, $r['token_hash'])) return (int)$r['id'];
            if (!empty($r['token_old_hash']) && password_verify($token, $r['token_old_hash'])) return (int)$r['id'];
        }
        // Fallback: legacy plaintext token match (to avoid breaking existing agents)
        $st = $pdo->prepare("SELECT id FROM nodes WHERE token=? LIMIT 1");
        $st->execute([$token]);
        $id = $st->fetchColumn();
        return $id ? (int)$id : null;
    }
}
