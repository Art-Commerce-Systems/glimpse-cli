<?php

namespace App\Support;

use GlimpseImg\ApiException;
use stdClass;

/**
 * A .glimpse-baseline.json file: a JSON list of already-processed files at
 * the scan root, so analyze and check skip them. Entries match on relative
 * path plus size plus xxh128 content hash; a file whose content changed
 * re-enters the scan. The hash is for change detection, not security, so
 * a fast non-cryptographic digest is the right tool.
 */
final class BaselineFile
{
    public const FILENAME = '.glimpse-baseline.json';

    /**
     * @param  array<string, array{size: int, xxh128: string}>  $files
     */
    private function __construct(private array $files) {}

    /**
     * Load the baseline at the directory. A missing or empty file is an
     * empty baseline; an unreadable or malformed one fails loudly so a
     * permission problem or a typo never turns into a silently
     * un-baselined (or fully skipped) scan.
     */
    public static function load(string $directory): self
    {
        $path = rtrim($directory, '/').'/'.self::FILENAME;

        if (! is_file($path)) {
            return new self([]);
        }

        $content = @file_get_contents($path);

        if ($content === false) {
            throw new ApiException("Could not read {$path}.");
        }

        $content = trim($content);

        if ($content === '') {
            return new self([]);
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded) || ! is_array($decoded['files'] ?? null)) {
            throw new ApiException("Malformed {$path}: expected a JSON object with a \"files\" object.");
        }

        $files = [];

        foreach ($decoded['files'] as $relative => $entry) {
            if (! is_string($relative) || ! is_array($entry) || ! is_int($entry['size'] ?? null) || ! is_string($entry['xxh128'] ?? null)) {
                throw new ApiException("Malformed {$path}: every entry needs an integer \"size\" and a string \"xxh128\".");
            }

            $files[$relative] = ['size' => $entry['size'], 'xxh128' => $entry['xxh128']];
        }

        return new self($files);
    }

    /**
     * Walk up from the directory and return the first one containing a
     * baseline file, or null when there is none. The walk stops at a
     * repository boundary (a directory containing .git) or the filesystem
     * root, so a stray baseline outside the project can never capture a
     * scan or a write.
     */
    public static function findRoot(string $directory): ?string
    {
        $dir = rtrim($directory, '/');

        if ($dir === '') {
            $dir = '/';
        }

        while (true) {
            if (is_file($dir.'/'.self::FILENAME)) {
                return $dir;
            }

            if (file_exists($dir.'/.git')) {
                return null;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                return null;
            }

            $dir = $parent;
        }
    }

    /**
     * The path of a file relative to a directory that contains it, with
     * separators normalized to forward slashes so baseline keys match
     * across platforms.
     */
    public static function relativePath(string $directory, string $path): string
    {
        $directory = str_replace('\\', '/', $directory);
        $path = str_replace('\\', '/', $path);

        return ltrim(substr($path, strlen(rtrim($directory, '/'))), '/');
    }

    /**
     * Whether the file is covered by the baseline: known path, same size,
     * same content. Size is compared first so the hash is only computed
     * when it could possibly match.
     */
    public function skips(string $relative, string $absolute): bool
    {
        $entry = $this->files[$relative] ?? null;

        if ($entry === null) {
            return false;
        }

        if (@filesize($absolute) !== $entry['size']) {
            return false;
        }

        return @hash_file('xxh128', $absolute) === $entry['xxh128'];
    }

    /**
     * Add or refresh the entry for the file from its current on-disk
     * content. A file that vanished or turned unreadable in the meantime
     * is skipped rather than crashing the run; it either no longer needs
     * an entry or will simply re-enter the next scan.
     */
    public function record(string $relative, string $absolute): void
    {
        $size = @filesize($absolute);
        $hash = $size === false ? false : @hash_file('xxh128', $absolute);

        if ($size === false || $hash === false) {
            return;
        }

        $this->put($relative, $size, $hash);
    }

    /**
     * Add or refresh an entry from a size and hash that were already
     * computed, e.g. from bytes the caller had in memory.
     */
    public function put(string $relative, int $size, string $hash): void
    {
        $this->files[$relative] = ['size' => $size, 'xxh128' => $hash];
    }

    public function forget(string $relative): void
    {
        unset($this->files[$relative]);
    }

    /**
     * Drop entries whose file no longer exists under the directory.
     */
    public function prune(string $directory): void
    {
        $root = rtrim($directory, '/');

        foreach (array_keys($this->files) as $relative) {
            if (! is_file($root.'/'.$relative)) {
                unset($this->files[$relative]);
            }
        }
    }

    public function count(): int
    {
        return count($this->files);
    }

    /**
     * Write the baseline out, failing loudly instead of quietly losing
     * entries: an unencodable filename or a failed write must never
     * replace a healthy committed baseline with garbage.
     */
    public function save(string $directory): void
    {
        ksort($this->files);

        $path = rtrim($directory, '/').'/'.self::FILENAME;

        $json = json_encode(['files' => $this->files === [] ? new stdClass : $this->files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new ApiException("Could not encode {$path}: ".json_last_error_msg().'. Is a filename not valid UTF-8?');
        }

        if (@file_put_contents($path, $json.PHP_EOL) === false) {
            throw new ApiException("Could not write {$path}.");
        }
    }
}
