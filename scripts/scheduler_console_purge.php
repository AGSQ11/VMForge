#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use VMForge\Core\DB;
use VMForge\Models\Job;
use PDO;

$pdo = DB::pdo();

// Existing backup scheduler (if present)
if (file_exists(__DIR__ . '/scheduler.php.orig')) {
    require __DIR__ . '/scheduler.php.orig';
}

// Auto-close expired console sessions
$exp = $pdo->query("SELECT node_id, listen_port FROM console_sessions WHERE expires_at < NOW()")->fetchAll(PDO::FETCH_ASSOC);
foreach ($exp as $s) {
    Job::enqueue((int)$s['node_id'], 'KVM_CONSOLE_CLOSE', ['listen_port'=>(int)$s['listen_port']]);
}
$pdo->query("DELETE FROM console_sessions WHERE expires_at < NOW()");
