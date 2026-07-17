<?php

namespace App\Commands\Concerns;

use App\Support\BaselineFile;
use App\Support\IgnoreFile;
use App\Support\Paths;
use GlimpseImg\ApiException;

trait UpdatesBaseline
{
    /**
     * Keep an existing .glimpse-baseline.json current after writing an
     * output image. The baseline and the .glimpseignore are the ones in
     * the current working directory, the same anchor the scans use: the
     * baseline gains an entry for the written output, plus one for the
     * source when the command transformed the source itself
     * (convert/optimize) and it lives under the same root. An in-place
     * write that deleted the source drops its stale entry. A stdout write
     * touches nothing on disk, an output outside the working directory
     * cannot have a root-relative key, and a file the ignore rules
     * exclude will never be scanned, so none of those record anything. A
     * baseline is never created here; that is `analyze
     * --update-baseline`'s job. Baseline problems must never fail a
     * transform that already succeeded, so errors (including a baseline
     * locked by another glimpse process) are reported as a warning on
     * STDERR instead of failing the command.
     */
    protected function recordInBaseline(string $input, string $outputPath, bool $recordSource = true): void
    {
        if ($outputPath === '-') {
            return;
        }

        $root = Paths::root();

        if (! is_file($root.'/'.BaselineFile::FILENAME)) {
            return;
        }

        $outputDir = realpath(dirname($outputPath));

        if ($outputDir === false) {
            return;
        }

        $output = $outputDir.'/'.basename($outputPath);

        if (! Paths::contains($root, $output)) {
            return;
        }

        try {
            $ignore = IgnoreFile::load($root);
            $baseline = BaselineFile::load($root, forUpdate: true);
            $relative = Paths::relativePath($root, $output);

            if (! $ignore->ignores($relative)) {
                $baseline->record($relative, $output);
            }

            if ($recordSource && $input !== '-') {
                $this->recordSource($baseline, $ignore, $root, $input, $output);
            }

            $baseline->save($root);
        } catch (ApiException $exception) {
            fwrite(STDERR, "Warning: baseline not updated: {$exception->getMessage()}".PHP_EOL);
        }
    }

    /**
     * Record the source next to the output, or drop its stale entry when
     * an in-place write already deleted it. The directory part is
     * canonicalized but the file name is kept as given, so a symlinked
     * image keeps the key a directory scan would produce for it. A source
     * outside the root or excluded by the ignore rules records nothing.
     */
    private function recordSource(BaselineFile $baseline, IgnoreFile $ignore, string $root, string $input, string $output): void
    {
        $sourceDir = realpath(dirname($input));

        if ($sourceDir === false) {
            return;
        }

        $source = $sourceDir.'/'.basename($input);

        if ($source === $output || ! Paths::contains($root, $source)) {
            return;
        }

        $relative = Paths::relativePath($root, $source);

        if ($ignore->ignores($relative)) {
            return;
        }

        is_file($source) ? $baseline->record($relative, $source) : $baseline->forget($relative);
    }
}
