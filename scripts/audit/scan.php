<?php
/**
 * VMForge Security Scanner (Pack 21)
 * - Scans PHP files for:
 *   1) Shell injection patterns (Shell::run with interpolated strings/backticks/system/exec/passthru)
 *   2) SQL concatenation (->query("...$var...") or "SELECT ...".$var)
 *   3) Raw echo of superglobals without escaping inside controllers/views
 * Outputs JSON and human-readable summary. Exit 1 if --strict and issues found.
 */
declare(strict_types=1);

$root = realpath(__DIR__ . '/../../') ?: getcwd();
$paths = [$root . '/src', $root . '/agent', $root . '/public'];
$issues = [];

function add_issue(&$issues, $file, $line, $type, $message, $snippet) {
    $issues[] = [
        'file' => $file,
        'line' => $line,
        'type' => $type,
        'message' => $message,
        'snippet' => $snippet
    ];
}

function scan_file($file, &$issues) {
    $contents = file_get_contents($file);
    if ($contents === false) return;
    $lines = explode("\n", $contents);

    // 1) Shell injection patterns
    $patterns_shell = [
        // Shell::run("...{$var}...")
        '~Shell::run\(\s*"(?:[^"\\\\]|\\\\.)*\$[{][^}]+[}](?:[^"\\\\]|\\\\.)*"\s*\)~',
        "~Shell::run\(\s*'(?:[^'\\\\]|\\\\.)*\\$[{][^}]+[}](?:[^'\\\\]|\\\\.)*'\s*\)~",
        // Shell::run("...$var...")
        '~Shell::run\(\s*"(?:[^"\\\\]|\\\\.)*\$[a-zA-Z_][a-zA-Z0-9_]*(?:[^"\\\\]|\\\\.)*"\s*\)~',
        "~Shell::run\(\s*'(?:[^'\\\\]|\\\\.)*\\$[a-zA-Z_][a-zA-Z0-9_]*(?:[^'\\\\]|\\\\.)*'\s*\)~",
        // Backticks, system/exec/passthru
        '~`[^`]*\$[a-zA-Z_{][^`]*`~',
        '~\b(system|exec|passthru|shell_exec)\s*\(\s*"(?:[^"\\\\]|\\\\.)*\$[a-zA-Z_{][^"]*"\s*\)~',
        "~\b(system|exec|passthru|shell_exec)\s*\(\s*'(?:[^'\\\\]|\\\\.)*\\$[a-zA-Z_{][^']*'\s*\)~",
    ];

    // 2) SQL concatenation
    $patterns_sql = [
        // ->query("SELECT ... $var ...")
        '~->\s*query\s*\(\s*"(?:[^"\\\\]|\\\\.)*\$[a-zA-Z_{][^"]*"\s*\)~',
        "~->\s*query\s*\(\s*'(?:[^'\\\\]|\\\\.)*\\$[a-zA-Z_{][^']*'\s*\)~",
        // string concatenation with . and variables
        '~->\s*query\s*\(\s*".*"\s*\.\s*\$[a-zA-Z_][a-zA-Z0-9_]*~',
        "~->\s*query\s*\(\s*'.*'\s*\.\s*\\$[a-zA-Z_][a-zA-Z0-9_]*~",
    ];

    // 3) Raw echo of superglobals
    $patterns_xss = [
        '~echo\s+\$_(GET|POST|REQUEST|SERVER)\[~',
        '~<\?=\s*\$_(GET|POST|REQUEST|SERVER)\[~'
    ];

    foreach ($lines as $i => $line) {
        foreach ($patterns_shell as $rx) {
            if (preg_match($rx, $line)) {
                add_issue($issues, $file, $i+1, 'shell', 'Possible shell injection (interpolated command)', trim($line));
            }
        }
        foreach ($patterns_sql as $rx) {
            if (preg_match($rx, $line)) {
                add_issue($issues, $file, $i+1, 'sql', 'Possible SQL concatenation (use prepared statements)', trim($line));
            }
        }
        foreach ($patterns_xss as $rx) {
            if (preg_match($rx, $line)) {
                add_issue($issues, $file, $i+1, 'xss', 'Raw echo of superglobal without escaping', trim($line));
            }
        }
    }
}

foreach ($paths as $p) {
    if (!is_dir($p)) continue;
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p));
    foreach ($rii as $file) {
        /** @var SplFileInfo $file */
        if ($file->isDir()) continue;
        if (substr($file->getFilename(), -4) !== '.php') continue;
        scan_file($file->getPathname(), $issues);
    }
}

// Output
$strict = in_array('--strict', $argv, true);
$fmt = in_array('--json', $argv, true) ? 'json' : 'text';

if ($fmt === 'json') {
    echo json_encode(['issues' => $issues, 'count' => count($issues)], JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    if (!$issues) {
        echo "[OK] No issues detected by static patterns.\n";
    } else {
        echo "[!] Issues found: " . count($issues) . "\n\n";
        foreach ($issues as $it) {
            echo $it['type'] . " | " . $it['file'] . ":" . $it['line'] . "\n  " . $it['message'] . "\n  > " . $it['snippet'] . "\n\n";
        }
    }
}
if ($strict && $issues) exit(1);
