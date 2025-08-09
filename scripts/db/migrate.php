\
    #!/usr/bin/env php
    <?php
    declare(strict_types=1);
    require __DIR__ . '/../../src/bootstrap.php';

    use VMForge\Core\DB;

    function logln($m){ fwrite(STDOUT, $m . PHP_EOL); }

    $dir = realpath(__DIR__ . '/../../migrations');
    if (!$dir || !is_dir($dir)) {
        fwrite(STDERR, "Migrations dir not found\n"); exit(2);
    }
    $pdo = DB::pdo();
    $files = glob($dir . '/*.sql');
    sort($files);

    $pdo->exec("CREATE TABLE IF NOT EXISTS _migrations (filename VARCHAR(255) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $done = $pdo->query("SELECT filename FROM _migrations")->fetchAll(PDO::FETCH_COLUMN) ?: [];

    foreach ($files as $f) {
        $base = basename($f);
        if (in_array($base, $done, true)) { continue; }
        $sql = file_get_contents($f);
        logln("Applying: " . $base);
        $pdo->exec($sql);
        $st = $pdo->prepare("INSERT INTO _migrations (filename) VALUES (?)");
        $st->execute([$base]);
    }
    logln("Migrations complete.");
