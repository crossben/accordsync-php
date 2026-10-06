<?php

// Refreshes contract/ (golden vectors and protocol schemas) from the Accord repository.
//
//   php tools/sync-contract.php            copy from ../app (ACCORD_APP_DIR to override)
//   php tools/sync-contract.php --check    fail if contract/ differs from the repository (CI)
//
// The contract is committed, so this repository builds and tests alone. A new vector or conformance
// test in the Accord repository must pass here before the next PHP release.

declare(strict_types=1);

$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);
$app = getenv('ACCORD_APP_DIR') ?: $root . '/../app';
if (!is_file("$app/vectors/lww.json")) {
    fwrite(STDERR, "sync-contract: no Accord repository at $app (set ACCORD_APP_DIR).\n");
    exit(1);
}

/** @return array<string, string> every .json file under $dir, by path relative to it */
function jsonFiles(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.json')) {
            $out[substr($file->getPathname(), strlen($dir) + 1)] = (string) file_get_contents($file->getPathname());
        }
    }
    ksort($out);

    return $out;
}

$problems = [];
foreach (['vectors', 'protocol/v1'] as $part) {
    $source = jsonFiles("$app/$part");
    $current = jsonFiles("$root/contract/$part");
    foreach (array_unique([...array_keys($source), ...array_keys($current)]) as $name) {
        if (($source[$name] ?? null) === ($current[$name] ?? null)) {
            continue;
        }
        $problems[] = "$part/$name";
        if ($check) {
            continue;
        }
        $target = "$root/contract/$part/$name";
        if (!isset($source[$name])) {
            unlink($target);
        } else {
            @mkdir(dirname($target), 0o777, true);
            file_put_contents($target, $source[$name]);
        }
    }
}

if ($check) {
    if ($problems !== []) {
        fwrite(STDERR, "contract/ is behind the Accord repository:\n  " . implode("\n  ", $problems) . "\n");
        fwrite(STDERR, "Run `composer sync-contract`, make the tests pass, and commit.\n");
        exit(1);
    }
    echo "contract/ matches the Accord repository.\n";
    exit(0);
}

$commit = trim((string) shell_exec('git -C ' . escapeshellarg($app) . ' rev-parse HEAD 2>/dev/null'));
file_put_contents("$root/contract/SOURCE", 'crossben/accordsync ' . ($commit !== '' ? $commit : '(commit unknown)') . "\n");
echo $problems === [] ? "contract/ already up to date.\n" : 'Updated ' . count($problems) . " file(s).\n";
