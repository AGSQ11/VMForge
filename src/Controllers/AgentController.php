<?php
namespace VMForge\Controllers;
use VMForge\Core\DB;
use VMForge\Core\Response;
use PDO;

class AgentController {
    public function poll() {
        $token = $_POST['token'] ?? '';
        if (!$token) return Response::json(['job'=>null]);
        $pdo = DB::pdo();
        // update last_seen for node
        $st = $pdo->prepare('UPDATE nodes SET last_seen=NOW() WHERE token=?');
        $st->execute([$token]);
        // fetch next queued job for this node
        $st = $pdo->prepare('SELECT * FROM jobs WHERE node_id=(SELECT id FROM nodes WHERE token=? LIMIT 1) AND status="queued" ORDER BY id ASC LIMIT 1');
        $st->execute([$token]);
        $job = $st->fetch(PDO::FETCH_ASSOC);
        if (!$job) return Response::json(['job'=>null]);
        // mark as running
        $pdo->prepare('UPDATE jobs SET status="running", started_at=NOW() WHERE id=?')->execute([(int)$job['id']]);
        return Response::json(['job'=>$job]);
    }
    public function ack() {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'done';
        $log = $_POST['log'] ?? '';
        if (!$id) return Response::json(['ok'=>false], 400);
        $pdo = DB::pdo();
        $pdo->prepare('UPDATE jobs SET status=?, finished_at=NOW(), log=? WHERE id=?')->execute([$status, $log, $id]);
        return Response::json(['ok'=>true]);
    }
}
