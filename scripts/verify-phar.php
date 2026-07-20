<?php

/**
 * Fails when the built phar contains a package that box.json excludes.
 *
 * Box config mistakes fail silently: exclude-dev-files is a no-op with an
 * explicit directories config, and blacklist ignores directory entries, which
 * is how every phar before v1.2.0 shipped with pest, phpunit, and phpstan
 * inside. BoxExcludeListTest guards box.json against composer.lock; this
 * guards the artifact against box.json.
 *
 * Run via `make verify-phar` (wired into `make build`).
 */
$root = dirname(__DIR__);
$pharPath = $root.'/builds/glimpse';

if (! is_file($pharPath)) {
    fwrite(STDERR, "builds/glimpse does not exist; build it before verifying.\n");
    exit(1);
}

$box = json_decode((string) file_get_contents($root.'/box.json'), true, 512, JSON_THROW_ON_ERROR);

$vendorFinders = array_values(array_filter(
    $box['finder'],
    fn (array $finder): bool => in_array('vendor', (array) ($finder['in'] ?? []), true),
));

if (count($vendorFinders) !== 1) {
    fwrite(STDERR, 'box.json must declare exactly one finder over vendor; found '.count($vendorFinders).".\n");
    exit(1);
}

$excludes = $vendorFinders[0]['exclude'] ?? [];

if ($excludes === []) {
    fwrite(STDERR, "no vendor excludes found in box.json.\n");
    exit(1);
}

// Phar refuses to open a file without a .phar extension, so work on a copy.
// The name is unique per run: a fixed one lets a stale copy be verified in
// place of a missing build, and races between concurrent checkouts.
$copy = sprintf('%s/glimpse-verify-%d-%s.phar', sys_get_temp_dir(), getmypid(), bin2hex(random_bytes(4)));

if (! copy($pharPath, $copy)) {
    fwrite(STDERR, "could not copy builds/glimpse to $copy.\n");
    exit(1);
}

try {
    $bundled = [];

    foreach (new RecursiveIteratorIterator(new Phar($copy)) as $file) {
        $path = str_replace('\\', '/', $file->getPathname());

        foreach ($excludes as $exclude) {
            if (str_contains($path, '/vendor/'.$exclude.'/')) {
                $bundled[$exclude] = true;
            }
        }
    }
} finally {
    @unlink($copy);
}

if ($bundled !== []) {
    fwrite(STDERR, 'builds/glimpse contains excluded packages: '.implode(', ', array_keys($bundled))."\n");
    exit(1);
}

echo 'builds/glimpse is clean: no excluded packages inside ('.count($excludes)." checked).\n";
