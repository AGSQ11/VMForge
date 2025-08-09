<?php
/**
 * VMForge Codemod (Shell::run -> Shell::runf/runArgs)
 * - Rewrites very simple patterns like: Shell::run("virsh start $name");
 * - Makes backups as *.bak and prints changed lines.
 * - You MUST review diffs.
 */
declare(strict_types=1);

$root = realpath(__DIR__ . '/../../') ?: getcwd();
$paths = [$root . '/src', $root . '/agent'];

function rewrite_file($path) {
    $src = file_get_contents($path);
    if ($src === false) return [false, "read failed"];
    $orig = $src;

    // Pattern: Shell::run("virsh <subcmd> $var")
    $src = preg_replace_callback('~Shell::run\(\s*"(virsh)\s+([a-z0-9:_ -]+)\s+\$([a-zA-Z_][a-zA-Z0-9_]*)"\s*\)~i',
        function($m){
            $bin = $m[1];
            $sub = trim($m[2]);
            $var = $m[3];
            $tokens = array_values(array_filter(preg_split('~\s+~', $sub)));
            $args = array_map(function($t){ return "'".$t."'"; }, $tokens);
            $args[] = '$'.$var;
            return "Shell::runArgs([".$bin."'," . implode(',', $args) . "])";
        }, $src);

    // Pattern: Shell::run("ip link set $iface up")
    $src = preg_replace_callback('~Shell::run\(\s*"(ip|nft|zfs)\s+([a-z0-9:_ -]+)\s+\$([a-zA-Z_][a-zA-Z0-9_]*)\s*([a-z0-9:_ -]*)"\s*\)~i',
        function($m){
            $bin = $m[1];
            $pre = trim($m[2]);
            $var = $m[3];
            $post = trim($m[4] ?? '');
            $tokens = array_values(array_filter(preg_split('~\s+~', $pre)));
            $args = array_map(function($t){ return "'".$t."'"; }, $tokens);
            $args[] = '$'.$var;
            if ($post !== '') {
                foreach (preg_split('~\s+~', $post) as $t) { if ($t !== '') $args[] = "'".$t."'"; }
            }
            return "Shell::runArgs([".$bin."'," . implode(',', $args) . "])";
        }, $src);

    if ($src !== $orig) {
        copy($path, $path . '.bak');
        file_put_contents($path, $src);
        return [true, "rewritten"];
    }
    return [false, "no changes"];
}

$changed = 0;
foreach ($paths as $p) {
    if (!is_dir($p)) continue;
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p));
    foreach ($rii as $file) {
        if ($file->isDir()) continue;
        if (substr($file->getFilename(), -4) !== '.php') continue;
        [$ok, $msg] = rewrite_file($file->getPathname());
        if ($ok) { echo "[changed] ".$file->getPathname()."\n"; $changed++; }
    }
}
echo "Done. Files changed: $changed\n";
