<?php
namespace VMForge\Controllers;

use VMForge\Core\Auth;
use VMForge\Core\DB;
use VMForge\Core\Security;

class APIController {
    public function listNodes() {
        Auth::require();
        header('Content-Type: application/json');
        $pdo = DB::pdo();
        $st = $pdo->query('SELECT id,name,mgmt_url,bridge FROM nodes ORDER BY id ASC');
        echo json_encode(['nodes'=>$st->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    public function createJob() {
        Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null); // if used cross-site, pass X_CSRF header instead
        header('Content-Type: application/json');

        $nodeId = isset($_POST['node_id']) ? (int)$_POST['node_id'] : 0;
        $type   = trim($_POST['type'] ?? '');
        $payload = $_POST['payload'] ?? '';
        if ($nodeId < 1 || $type === '' || $payload === '') { http_response_code(400); echo json_encode(['error'=>'missing fields']); return; }
        // Validate payload JSON
        $data = json_decode($payload, true);
        if ($data === null) { http_response_code(400); echo json_encode(['error'=>'invalid JSON']); return; }

        // Verify node exists (prepared)
        $st = DB::pdo()->prepare('SELECT id FROM nodes WHERE id=?');
        $st->execute([$nodeId]);
        if (!$st->fetchColumn()) { http_response_code(404); echo json_encode(['error'=>'node not found']); return; }

        // Enqueue
        $ins = DB::pdo()->prepare('INSERT INTO jobs(node_id, type, payload, status, created_at) VALUES (?,?,?,\'pending\', NOW())');
        $ins->execute([$nodeId, $type, json_encode($data)]);

        echo json_encode(['ok'=>true]);
    }
}
