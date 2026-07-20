<?php

/**
 * Guards the exclude list in box.json against drifting from composer.lock.
 *
 * Since the package went phar-only (require = php only, everything else in
 * require-dev), the lock no longer distinguishes runtime packages from dev
 * tooling: Box would bundle pest, phpunit, and phpstan into the phar unless
 * box.json excludes them by hand. These tests recompute the runtime set by
 * walking the dependency graph in composer.lock from RUNTIME_ROOTS, so adding
 * a dependency without updating box.json (or RUNTIME_ROOTS) fails loudly here
 * instead of silently growing or breaking the phar.
 */

/**
 * The packages the phar needs at runtime; the former composer.json require
 * list. A new runtime dependency must be added here, a new dev tool must be
 * added to the vendor exclude list in box.json.
 */
const RUNTIME_ROOTS = [
    'mathiasgrimm/glimpse-php',
    'illuminate/http',
    'laravel-zero/framework',
    'laravel-zero/phar-updater',
    'symfony/finder',
];

/**
 * Installed packages keyed by name, and the runtime closure of RUNTIME_ROOTS
 * resolved through require/provide/replace.
 *
 * @return array{0: array<string, array<string, mixed>>, 1: array<int, string>}
 */
function lockRuntimeClosure(): array
{
    $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);

    $installed = [];
    foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
        $installed[$package['name']] = $package;
    }

    // Providers are collected from every installed package, dev ones included,
    // and version constraints are ignored. That is deliberately conservative:
    // an unresolvable name pulls in anything that could satisfy it, so the walk
    // can only ever over-report the runtime set. Over-reporting means a dev
    // package escapes the exclude list and the phar grows; under-reporting
    // would drop a needed package and break the phar at runtime.
    $providers = [];
    foreach ($installed as $name => $package) {
        foreach (array_keys(($package['provide'] ?? []) + ($package['replace'] ?? [])) as $virtual) {
            $providers[$virtual][] = $name;
        }
    }

    $closure = [];
    $queue = RUNTIME_ROOTS;
    while ($queue !== []) {
        $name = array_pop($queue);
        if (isset($closure[$name])) {
            continue;
        }
        if (! isset($installed[$name])) {
            foreach ($providers[$name] ?? [] as $provider) {
                $queue[] = $provider;
            }

            continue;
        }
        $closure[$name] = true;
        foreach (array_keys($installed[$name]['require'] ?? []) as $dependency) {
            $queue[] = $dependency;
        }
    }

    return [$installed, array_keys($closure)];
}

/**
 * The exclude entries of the single vendor finder in box.json, always full
 * package names ('laravel/pint'). Fails when box.json grows a second vendor
 * finder, which would leave this test and make verify-phar reading different
 * lists.
 *
 * @return array<int, string>
 */
function boxVendorExcludes(): array
{
    $box = json_decode((string) file_get_contents(base_path('box.json')), true);

    $vendorFinders = array_values(array_filter(
        $box['finder'],
        fn (array $finder): bool => in_array('vendor', (array) ($finder['in'] ?? []), true),
    ));

    expect($vendorFinders)->toHaveCount(1, 'box.json must declare exactly one finder over vendor; this test and the verify-phar target both assume a single exclude list.');

    return $vendorFinders[0]['exclude'] ?? [];
}

function isExcludedFromPhar(string $package, array $excludes): bool
{
    return in_array($package, $excludes, true);
}

test('every locked package is either a runtime dependency or excluded from the phar', function () {
    [$installed, $closure] = lockRuntimeClosure();
    $excludes = boxVendorExcludes();

    $uncovered = array_values(array_filter(
        array_keys($installed),
        fn (string $name): bool => ! in_array($name, $closure, true) && ! isExcludedFromPhar($name, $excludes),
    ));

    expect($uncovered)->toBe([], 'These packages would be bundled into the phar. A dev tool belongs in the vendor exclude list in box.json; a runtime dependency belongs in RUNTIME_ROOTS in this test: '.implode(', ', $uncovered));
});

test('no runtime dependency is excluded from the phar', function () {
    [, $closure] = lockRuntimeClosure();
    $excludes = boxVendorExcludes();

    $wronglyExcluded = array_values(array_filter(
        $closure,
        fn (string $name): bool => isExcludedFromPhar($name, $excludes),
    ));

    expect($wronglyExcluded)->toBe([], 'These packages are needed at runtime but the vendor exclude list in box.json would keep them out of the phar: '.implode(', ', $wronglyExcluded));
});

test('every exclude entry is a full package name, never a bare vendor directory', function () {
    $bare = array_values(array_filter(
        boxVendorExcludes(),
        fn (string $exclude): bool => ! str_contains($exclude, '/'),
    ));

    expect($bare)->toBe([], 'Symfony Finder drops every directory whose basename matches a slashless exclude, at any depth: a bare "phpstan" entry also stripped tools/phpstan out of two runtime packages. Use the full package name instead: '.implode(', ', $bare));
});

test('the runtime roots are still installed packages', function () {
    [$installed] = lockRuntimeClosure();

    expect(array_values(array_diff(RUNTIME_ROOTS, array_keys($installed))))->toBe([]);
});
