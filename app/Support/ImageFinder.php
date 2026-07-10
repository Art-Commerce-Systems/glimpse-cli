<?php

namespace App\Support;

use App\Enums\ImageFormat;
use FilesystemIterator;
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
     * a file. Symlinks to image files still resolve normally.
     *
     * @return list<string>
     */
    public function find(string $directory): array
    {
        $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $file): bool {
                if (str_starts_with($file->getFilename(), '.')) {
                    return false;
                }

                if ($file->isDir()) {
                    return ! $file->isLink();
                }

                return ImageFormat::fromExtension($file->getExtension()) !== null;
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
