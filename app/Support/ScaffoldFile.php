<?php

namespace MathiasGrimm\GlimpseCli\Support;

use MathiasGrimm\GlimpsePhp\ApiException;

/**
 * Writes the files init scaffolds from its templates, safely in both
 * modes: creation is exclusive, so a file that appeared between the
 * caller's existence check and the write fails the write instead of
 * being truncated, and replacement goes through a private temporary
 * file swapped in with an atomic rename, so a failed or short write
 * leaves the previous file untouched. Symbolic links anywhere below
 * the root are refused, because writing through one would silently
 * land the file wherever the link points, possibly outside the
 * project. Missing parent directories are created.
 */
final class ScaffoldFile
{
    public static function write(string $root, string $relativePath, string $content, bool $replace = false): void
    {
        $root = rtrim($root, '/');
        $path = $root.'/'.$relativePath;

        self::refuseSymlinks($root, $relativePath);

        self::ensureDirectory(dirname($path));

        $replace
            ? self::swapIn($path, $content)
            : self::createExclusive($path, $content);
    }

    /**
     * Refuse the write when the target, or any component of the
     * relative path below the root, is a symbolic link. Only the
     * relative components are judged: directories above the root are
     * the user's own filesystem layout.
     */
    private static function refuseSymlinks(string $root, string $relativePath): void
    {
        $prefix = $root;

        foreach (explode('/', $relativePath) as $segment) {
            $prefix .= '/'.$segment;

            if (is_link($prefix)) {
                throw new ApiException("Could not write {$root}/{$relativePath}: {$prefix} is a symbolic link.");
            }
        }
    }

    /**
     * The silenced mkdir plus re-check tolerates a concurrent mkdir of
     * the same path; a parent that exists as a regular file (a repo
     * with a `.github` file, say) still fails cleanly here.
     */
    private static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new ApiException("Could not create the directory {$directory}.");
        }
    }

    private static function createExclusive(string $path, string $content): void
    {
        if (! self::writeNew($path, $content)) {
            throw new ApiException("Could not write {$path}.");
        }
    }

    private static function swapIn(string $path, string $content): void
    {
        $temporary = dirname($path).'/.'.basename($path).'.'.bin2hex(random_bytes(6)).'.tmp';

        if (! self::writeNew($temporary, $content) || ! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new ApiException("Could not write {$path}.");
        }
    }

    /**
     * Write content to a brand-new file (exclusive create) and report
     * success only after the bytes are flushed and the handle closed
     * cleanly: a full disk surfaces at flush or close, not at fwrite,
     * whose stream buffer happily accepts a short template. A failed
     * write removes the partial file; a failed open (the path already
     * exists) removes nothing, so a file that appeared since the
     * caller's existence check is never deleted.
     */
    private static function writeNew(string $path, string $content): bool
    {
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            return false;
        }

        $ok = @fwrite($handle, $content) === strlen($content) && @fflush($handle);
        $ok = @fclose($handle) && $ok;

        if (! $ok) {
            @unlink($path);
        }

        return $ok;
    }
}
