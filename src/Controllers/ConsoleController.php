<?php
namespace VMForge\Controllers;
use VMForge\Core\Response;
use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\DB;
use VMForge\Core\UUID;
use VMForge\Models\ConsoleSession;
use VMForge\Models\VM;
use VMForge\Models\Node;
use VMForge\Models\Job;
use PDO;

class ConsoleController {
    // Open console: enqueue job on agent to start websockify and redirect user
    public function open() {
        Auth::require();
        $uuid = $_GET['uuid'] ?? '';
        if (!$uuid) { http_response_code(400); echo 'missing uuid'; return; }
        $pdo = DB::pdo();
        $st = $pdo->prepare('SELECT * FROM vm_instances WHERE uuid=? LIMIT 1');
        $st->execute([$uuid]);
        $vm = $st->fetch(PDO::FETCH_ASSOC);
        if (!$vm || $vm['type'] !== 'kvm') { http_response_code(404); echo 'vm not found or not KVM'; return; }
        $nodeId = (int)$vm['node_id'];
        $node = $pdo->query('SELECT * FROM nodes WHERE id='.(int)$nodeId)->fetch(PDO::FETCH_ASSOC);
        if (!$node) { http_response_code(404); echo 'node not found'; return; }

        // choose a listen port (6100-6999)
        $listen = 6100 + random_int(0, 899);
        $token = bin2hex(random_bytes(16));
        $exp = (new \DateTime('+10 minutes'))->format('Y-m-d H:i:s');
        ConsoleSession::create([
            'vm_uuid' => $uuid,
            'node_id' => $nodeId,
            'token' => $token,
            'listen_port' => $listen,
            'expires_at' => $exp,
        ]);

        // enqueue job to start console on agent
        Job::enqueue($nodeId, 'KVM_CONSOLE_OPEN', [
            'name' => $vm['name'],
            'listen_port' => $listen,
        ]);

        // redirect via controller to avoid exposing port directly in UI
        header('Location: /console/redirect?token='.$token);
    }

    // Redirect user to node's noVNC URL
    public function redirect() {
        Auth::require();
        ConsoleSession::purgeExpired();
        $token = $_GET['token'] ?? '';
        if (!$token) { http_response_code(400); echo 'missing token'; return; }
        $s = ConsoleSession::findByToken($token);
        if (!$s) { http_response_code(404); echo 'session not found'; return; }
        $pdo = DB::pdo();
        $node = $pdo->query('SELECT * FROM nodes WHERE id='.(int)$s['node_id'])->fetch(PDO::FETCH_ASSOC);
        if (!$node) { http_response_code(404); echo 'node not found'; return; }
        $host = parse_url($node['mgmt_url'] ?? '', PHP_URL_HOST) ?: $node['mgmt_url'];
        $scheme = parse_url($node['mgmt_url'] ?? '', PHP_URL_SCHEME) ?: 'http';
        $port = (int)$s['listen_port'];
        $url = sprintf('%s://%s:%d/vnc.html?autoconnect=1&resize=scale', $scheme, $host, $port);
        // simple HTML with info and a link in case auto-redirect blocked
        echo '<!doctype html><meta http-equiv="refresh" content="1;url='.htmlspecialchars($url).'">';
        echo '<div style="font-family:system-ui;padding:20px;color:#e2e8f0;background:#0f172a">';
        echo '<h2>Launching console…</h2>';
        echo '<p>If not redirected, <a style="color:#93c5fd" href="'.htmlspecialchars($url).'">click here</a>.</p>';
        echo '<p>Ensure the agent node has <code>novnc</code> and <code>websockify</code> installed.</p>';
        echo '</div>';
    }

    // Close console: ask agent to kill websockify port
    public function close() {
        Auth::require();
        $token = $_GET['token'] ?? '';
        if (!$token) { http_response_code(400); echo 'missing token'; return; }
        $s = ConsoleSession::findByToken($token);
        if (!$s) { http_response_code(404); echo 'session not found'; return; }
        Job::enqueue((int)$s['node_id'], 'KVM_CONSOLE_CLOSE', [
            'listen_port' => (int)$s['listen_port'],
        ]);
        echo 'Close requested';
    }
}
