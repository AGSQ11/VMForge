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

        $listen = 6100 + random_int(0, 899);
        $token = bin2hex(random_bytes(16));
        $exp = (new \DateTime('+10 minutes'))->format('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        ConsoleSession::create([
            'vm_uuid' => $uuid,
            'node_id' => $nodeId,
            'token' => $token,
            'listen_port' => $listen,
            'expires_at' => $exp,
            'requester_ip' => $ip,
        ]);

        Job::enqueue($nodeId, 'KVM_CONSOLE_OPEN', [
            'name' => $vm['name'],
            'listen_port' => $listen,
        ]);

        header('Location: /console/redirect?token='.$token);
    }

    public function redirect() {
        Auth::require();
        ConsoleSession::purgeExpired();
        $token = $_GET['token'] ?? '';
        if (!$token) { http_response_code(400); echo 'missing token'; return; }
        $s = ConsoleSession::findByToken($token);
        if (!$s) { http_response_code(404); echo 'session not found'; return; }
        if (strtotime($s['expires_at']) < time()) { http_response_code(410); echo 'session expired'; return; }
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!empty($s['requester_ip']) && $ip && $ip !== $s['requester_ip']) {
            http_response_code(403); echo 'ip mismatch'; return;
        }
        $pdo = DB::pdo();
        $node = $pdo->query('SELECT * FROM nodes WHERE id='.(int)$s['node_id'])->fetch(PDO::FETCH_ASSOC);
        if (!$node) { http_response_code(404); echo 'node not found'; return; }
        $host = parse_url($node['mgmt_url'] ?? '', PHP_URL_HOST) ?: $node['mgmt_url'];
        $scheme = parse_url($node['mgmt_url'] ?? '', PHP_URL_SCHEME) ?: 'http';
        $port = (int)$s['listen_port'];
        $url = sprintf('%s://%s:%d/vnc.html?autoconnect=1&resize=scale', $scheme, $host, $port);
        echo '<!doctype html><meta http-equiv="refresh" content="1;url='.htmlspecialchars($url).'">';
        echo '<div style="font-family:system-ui;padding:20px;color:#e2e8f0;background:#0f172a">';
        echo '<h2>Launching console…</h2>';
        echo '<p>If not redirected, <a style="color:#93c5fd" href="'.htmlspecialchars($url).'">click here</a>.</p>';
        echo '</div>';
    }

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
