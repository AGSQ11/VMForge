#!/usr/bin/env php
<?php
// scripts/hash_node_tokens.php
require __DIR__ . '/../src/bootstrap.php';

use VMForge\Core\DB;

$pdo = DB::pdo();
$rows = $pdo->query('SELECT id, token, token_hash FROM nodes')->fetchAll(PDO::FETCH_ASSOC);
$updated = 0;
foreach ($rows as $r) {
    if (!empty($r['token']) && empty($r['token_hash'])) {
        $hash = password_hash($r['token'], PASSWORD_ARGON2ID);
        $st = $pdo->prepare('UPDATE nodes SET token_hash=? WHERE id=?');
        $st->execute([$hash, $r['id']]);
        $updated++;
    }
}
echo "Updated {$updated} node tokens to hashed form.\n";
