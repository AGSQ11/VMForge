<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use VMForge\Core\DB;

header('Content-Type: text/plain; version=0.0.4');

$pdo = DB::pdo();

function qval($sql, $args = []) {
    $pdo = DB::pdo();
    $st = $pdo->prepare($sql); $st->execute($args);
    $v = $st->fetchColumn(); return $v === false ? 0 : (int)$v;
}

$users_total   = qval('SELECT COUNT(*) FROM users');
$nodes_total   = qval('SELECT COUNT(*) FROM nodes');
$nodes_active  = qval('SELECT COUNT(*) FROM nodes WHERE last_seen_at IS NOT NULL AND last_seen_at > (NOW() - INTERVAL 120 SECOND)');
$vms_total     = qval('SELECT COUNT(*) FROM vm_instances');
$jobs_pending  = qval("SELECT COUNT(*) FROM jobs WHERE status='pending'");
$jobs_running  = qval("SELECT COUNT(*) FROM jobs WHERE status='in_progress'");
$jobs_failed   = qval("SELECT COUNT(*) FROM jobs WHERE status='failed'"); 
$alerts_open   = qval('SELECT COUNT(*) FROM alerts WHERE acknowledged = 0');

echo "# HELP vmforge_users_total Total users\n";
echo "# TYPE vmforge_users_total gauge\n";
echo "vmforge_users_total {$users_total}\n\n";

echo "# HELP vmforge_nodes_total Total nodes\n";
echo "# TYPE vmforge_nodes_total gauge\n";
echo "vmforge_nodes_total {$nodes_total}\n\n";

echo "# HELP vmforge_nodes_active Nodes seen in last 120s\n";
echo "# TYPE vmforge_nodes_active gauge\n";
echo "vmforge_nodes_active {$nodes_active}\n\n";

echo "# HELP vmforge_vms_total Total VM instances\n";
echo "# TYPE vmforge_vms_total gauge\n";
echo "vmforge_vms_total {$vms_total}\n\n";

echo "# HELP vmforge_jobs_pending Pending jobs\n";
echo "# TYPE vmforge_jobs_pending gauge\n";
echo "vmforge_jobs_pending {$jobs_pending}\n\n";

echo "# HELP vmforge_jobs_running Jobs in progress\n";
echo "# TYPE vmforge_jobs_running gauge\n";
echo "vmforge_jobs_running {$jobs_running}\n\n";

echo "# HELP vmforge_jobs_failed_total Failed jobs (current count)\n";
echo "# TYPE vmforge_jobs_failed_total gauge\n";
echo "vmforge_jobs_failed_total {$jobs_failed}\n\n";

echo "# HELP vmforge_alerts_open_total Open alerts\n";
echo "# TYPE vmforge_alerts_open_total gauge\n";
echo "vmforge_alerts_open_total {$alerts_open}\n\n";

// Per-node up metric
$st = $pdo->query('SELECT id, name, last_seen_at FROM nodes');
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $up = (!empty($row['last_seen_at']) && strtotime($row['last_seen_at']) > time() - 120) ? 1 : 0;
    $name = $row['name'] ?? ('node_' . $row['id']);
    $safe = preg_replace('~[^a-zA-Z0-9:_]~', '_', $name);
    echo "vmforge_node_up{node=\"{$safe}\"} {$up}\n";
}
