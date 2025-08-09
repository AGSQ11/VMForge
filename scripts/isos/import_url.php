#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use VMForge\Services\ISOStore;

if ($argc < 2) {
    fwrite(STDERR, "Usage: scripts/isos/import_url.php <url> [name] [sha256]\n");
    exit(1);
}
$url = $argv[1];
$name = $argv[2] ?? null;
$sha  = $argv[3] ?? null;

try {
    [$id, $path] = ISOStore::importUrl($url, $name, $sha);
    echo "Imported ISO id={$id} path={$path}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(2);
}
