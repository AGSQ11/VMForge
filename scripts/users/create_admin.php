#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use VMForge\Core\DB;
use VMForge\Core\Password;

if ($argc < 3) {
    fwrite(STDERR, "Usage: scripts/users/create_admin.php <email> <password>\n");
    exit(1);
}
$email = $argv[1];
$pass  = $argv[2];

$pdo = DB::pdo();
$hash = Password::hash($pass);
$pdo->prepare('INSERT INTO users (email, password_hash, role) VALUES (?, ?, "admin")')
    ->execute([$email, $hash]);
echo "Admin user created: {$email}\n";
