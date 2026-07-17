<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Path helpers for the working-directory anchor shared by the baseline
 * and the ignore file: glimpse reads both from the directory it is run
 * from, the way composer, phpunit, and phpstan resolve their config, and
 * keys every entry relative to it. All paths are normalized to forward
 * slashes so keys match across platforms.
 */
final class Paths
{
    /**
     * The directory whose baseline and ignore file govern this run:
     * always the current working directory. No upward search, so a file
     * elsewhere can never capture a scan or a write; run glimpse from the
     * project root to use the project's configuration.
     */
    public static function root(): string
    {
        return rtrim(str_replace('\\', '/', (string) getcwd()), '/');
    }

    /**
     * The canonical form of a directory: its realpath with separators
     * normalized to forward slashes, or the given path minus any trailing
     * slash when it cannot be resolved. Must match root()'s normalization
     * exactly, or the root-equality check in keyPrefix() misfires.
     */
    public static function canonical(string $dir): string
    {
        $real = realpath($dir);

        return $real === false
            ? rtrim(str_replace('\\', '/', $dir), '/')
            : str_replace('\\', '/', $real);
    }

    /**
     * Whether the path lies strictly inside the directory. Both are
     * expected to be canonical absolute paths; separators are normalized
     * before comparing.
     */
    public static function contains(string $directory, string $path): bool
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        $path = str_replace('\\', '/', $path);

        return $directory !== '' && str_starts_with($path, $directory.'/');
    }

    /**
     * The path of a file relative to a directory that contains it. A path
     * outside the directory throws: a blind substr would silently produce
     * a wrong key (and a sibling like /scan/rootbeer under /scan/root a
     * mangled one), quietly corrupting the baseline instead of surfacing
     * the caller's bug.
     */
    public static function relativePath(string $directory, string $path): string
    {
        $directory = str_replace('\\', '/', $directory);
        $path = str_replace('\\', '/', $path);
        $base = rtrim($directory, '/');

        if ($base !== '' && ! str_starts_with($path, $base.'/')) {
            throw new InvalidArgumentException("{$path} is not inside {$directory}.");
        }

        return ltrim(substr($path, strlen($base)), '/');
    }

    /**
     * The key prefix that maps scan-relative paths to root-relative keys,
     * e.g. 'assets/' when scanning <root>/assets. Empty when the scan
     * directory is the root itself, null when it lies outside the root:
     * its files cannot have root-relative keys, so neither the baseline
     * nor the ignore file applies to them.
     */
    public static function keyPrefix(string $root, string $dir): ?string
    {
        $real = self::canonical($dir);

        if ($real === $root) {
            return '';
        }

        return self::contains($root, $real)
            ? self::relativePath($root, $real).'/'
            : null;
    }
}
