#!/usr/bin/env php
<?php
require __DIR__ . '/../src/bootstrap.php';

use VMForge\Core\DB;
use VMForge\Core\Security;

$email = $argv[1] ?? null;
$name  = $argv[2] ?? 'cli-token';
if (!$email) {
    fwrite(STDERR, "Usage: php scripts/create_token.php <user_email> [name]\n");
    exit(1);
}
$st = DB::pdo()->prepare('SELECT id FROM users WHERE email=?');
$st->execute([$email]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    fwrite(STDERR, "User not found\n");
    exit(1);
}
$token = bin2hex(random_bytes(24));
$hash = Security::hashToken($token);
DB::pdo()->prepare('INSERT INTO api_tokens(user_id, token_hash, name, scopes) VALUES(?,?,?,?)')
    ->execute([$row['id'], $hash, $name, 'api:*']);
echo "TOKEN: {$token}\n";
