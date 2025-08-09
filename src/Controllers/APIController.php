<?php
namespace VMForge\Controllers;
use VMForge\Core\Response;
use VMForge\Models\Node;
use VMForge\Models\Job;

class APIController {
    private function auth(): bool {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        // Simple token format: "Bearer <token>" where token is any node token for demo
        if (str_starts_with($hdr, 'Bearer ')) {
            return true;
        }
        return false;
    }
    public function listNodes() {
        if (!$this->auth()) return Response::json(['error'=>'unauthorized'], 401);
        return Response::json(['nodes' => Node::all()]);
    }
    public function createJob() {
        if (!$this->auth()) return Response::json(['error'=>'unauthorized'], 401);
        $input = json_decode(file_get_contents('php://input') ?: '[]', true);
        $nodeId = (int)($input['node_id'] ?? 0);
        $type = $input['type'] ?? 'KVM_CREATE';
        $payload = $input['payload'] ?? [];
        $jobId = Job::enqueue($nodeId, $type, $payload);
        return Response::json(['job_id'=>$jobId], 201);
    }
}
