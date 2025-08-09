<?php
namespace VMForge\Controllers;
use VMForge\Core\Response;
use VMForge\Models\Node;
use VMForge\Models\Job;

class AgentController {
    public function poll() {
        $token = $_POST['token'] ?? '';
        $node = Node::byToken($token);
        if (!$node) return Response::json(['error'=>'unauthorized'], 401);
        $job = Job::poll((int)$node['id']);
        if (!$job) return Response::json(['job'=>null]);
        return Response::json(['job'=>$job]);
    }
    public function ack() {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'failed';
        $log = $_POST['log'] ?? '';
        Job::ack($id, $status, $log);
        return Response::json(['ok'=>true]);
    }
}
