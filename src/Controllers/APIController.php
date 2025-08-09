<?php
namespace VMForge\Controllers;
use VMForge\Core\Response;
use VMForge\Models\Node;
use VMForge\Models\Job;

class APIController {
    public function listNodes() {
        $auth = \VMForge\Core\APIAuth::userFromBearer();
        if (!$auth) return Response::json(['error'=>'unauthorized'], 401);
        return Response::json(['nodes' => Node::all()]);
    }
    public function createJob() {
        $auth = \VMForge\Core\APIAuth::userFromBearer();
        if (!$auth) return Response::json(['error'=>'unauthorized'], 401);
        $input = json_decode(file_get_contents('php://input') ?: '[]', true);
        $nodeId = (int)($input['node_id'] ?? 0);
        $type = $input['type'] ?? 'KVM_CREATE';
        $payload = $input['payload'] ?? [];
        $jobId = Job::enqueue($nodeId, $type, $payload);
        return Response::json(['job_id'=>$jobId], 201);
    }
}
