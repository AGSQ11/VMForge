#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use VMForge\Core\DB;
use VMForge\Core\Env;

$pdo = DB::pdo();

$staleSec = (int)Env::get('ALERT_NODE_STALE_SEC', '180');
$maxPending = (int)Env::get('ALERT_JOBS_PENDING_MAX', '100');

// ensure tables exist (idempotent safety)
$pdo->exec("CREATE TABLE IF NOT EXISTS alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  resource VARCHAR(128) NOT NULL,
  severity ENUM('info','warning','critical') NOT NULL,
  type VARCHAR(64) NOT NULL,
  message TEXT NOT NULL,
  acknowledged TINYINT(1) DEFAULT 0,
  acknowledged_by INT NULL,
  acknowledged_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_severity (severity, acknowledged),
  INDEX idx_resource_alerts (resource, created_at)
) ENGINE=InnoDB");

// 1) stale nodes
$q = $pdo->prepare('SELECT id, name, last_seen_at FROM nodes');
$q->execute();
while ($n = $q->fetch(PDO::FETCH_ASSOC)) {
    $id = (int)$n['id'];
    $name = $n['name'] ?? ('node_'.$id);
    $ls = $n['last_seen_at'] ?? null;
    $stale = !$ls || (strtotime($ls) < time() - $staleSec);
    if ($stale) {
        add_alert($pdo, 'node:'.$id, 'critical', 'node_stale', "Node {$name} has not checked in for {$staleSec}+ seconds");
    }
}

// 2) job backlog
$pending = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE status='pending'")->fetchColumn();
if ($pending > $maxPending) {
    add_alert($pdo, 'jobs', 'warning', 'queue_backlog', "Pending jobs: {$pending}, threshold: {$maxPending}");
}

echo "alerts sweep done\n";

function add_alert(PDO $pdo, string $res, string $sev, string $type, string $msg): void {
    $st = $pdo->prepare('INSERT INTO alerts(resource,severity,type,message) VALUES (?,?,?,?)');
    $st->execute([$res,$sev,$type,$msg]);
}
