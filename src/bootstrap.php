<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

spl_autoload_register(function($class) {
    $prefix = 'VMForge\\';
    $base_dir = __DIR__ . '/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) require $file;
});

use VMForge\Core\Env;
Env::load(__DIR__ . '/../.env');

