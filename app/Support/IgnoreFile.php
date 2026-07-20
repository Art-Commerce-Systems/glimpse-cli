<?php

namespace MathiasGrimm\GlimpseCli\Support;

use Symfony\Component\Finder\Gitignore;

/**
 * A .glimpseignore file: gitignore syntax, one file in the directory
 * glimpse is run from, with patterns matched against paths relative to
 * it. Negated patterns (!keep.png) work, with git's caveat that a file
 * inside an ignored directory cannot be re-included, because ignored
 * directories are pruned during the scan.
 */
final class IgnoreFile
{
    public const FILENAME = '.glimpseignore';

    private function __construct(private readonly ?string $regex) {}

    public static function load(string $directory): self
    {
        $path = rtrim($directory, '/').'/'.self::FILENAME;

        if (! is_file($path)) {
            return self::none();
        }

        $content = (string) file_get_contents($path);

        return $content === '' ? self::none() : new self(Gitignore::toRegex($content));
    }

    public static function none(): self
    {
        return new self(null);
    }

    /**
     * Whether the path is ignored. The path must be relative to the scan
     * root, without a leading slash. Directories are matched with a
     * trailing slash, the way git matches `dir/` patterns.
     */
    public function ignores(string $relativePath, bool $isDirectory = false): bool
    {
        if ($this->regex === null) {
            return false;
        }

        $path = str_replace('\\', '/', $relativePath);

        if ($isDirectory && ! str_ends_with($path, '/')) {
            $path .= '/';
        }

        return preg_match($this->regex, $path) === 1;
    }
}
