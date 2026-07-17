<?php

namespace App\Support;

use FilesystemIterator;
use GlimpseImg\ImageFormat;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ImageFinder
{
    /**
     * Find every image file under the directory, recursively, sorted by
     * pathname. Dot entries (directories and files) are skipped: .git is
     * never wanted, and macOS AppleDouble files (._photo.jpg) carry image
     * extensions without being images. Symlinked directories (Laravel's
     * public/storage) are skipped too: the iterator will not recurse into
     * them, and letting them through yields the link itself as if it were
     * a file. Symlinks to image files still resolve normally. A
     * .glimpseignore file in the current working directory excludes
     * further paths, matched by their working-directory-relative path; a
     * directory scanned from outside the working directory is beyond the
     * ignore file's reach, so nothing in it is excluded.
     *
     * @return list<string>
     */
    public function find(string $directory): array
    {
        $root = rtrim($directory, '/');
        $prefix = Paths::keyPrefix(Paths::root(), $directory);
        $ignore = $prefix === null ? IgnoreFile::none() : IgnoreFile::load(Paths::root());

        $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $file) use ($root, $prefix, $ignore): bool {
                if (str_starts_with($file->getFilename(), '.')) {
                    return false;
                }

                if ($file->isDir() && $file->isLink()) {
                    return false;
                }

                if (! $file->isDir() && ImageFormat::fromExtension($file->getExtension()) === null) {
                    return false;
                }

                $relative = $prefix.ltrim(substr($file->getPathname(), strlen($root)), '/\\');

                return ! $ignore->ignores($relative, $file->isDir());
            },
        ));

        $paths = [];

        foreach ($files as $file) {
            $paths[] = $file->getPathname();
        }

        sort($paths, SORT_STRING);

        return $paths;
    }
}
