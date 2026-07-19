<?php

namespace App\Commands;

use App\Commands\Concerns\GuardsApiErrors;
use App\Support\BaselineFile;
use App\Support\IgnoreFile;
use App\Support\Paths;
use GlimpseImg\ApiException;
use LaravelZero\Framework\Commands\Command;

class InitCommand extends Command
{
    use GuardsApiErrors;

    protected $signature = 'init
        {--update-baseline : Seed the baseline by scanning the current directory (runs analyze . --update-baseline)}
        {--force : Recreate .glimpseignore from the starter template even when it exists}';

    protected $description = 'Set up the current directory for glimpse: a starter .glimpseignore and the baseline';

    /**
     * The starter .glimpseignore. One static template, no project-type
     * detection: the patterns are harmless on projects where they match
     * nothing.
     */
    private const IGNORE_TEMPLATE = <<<'GITIGNORE'
        # Created by glimpse init. Paths listed here are excluded from glimpse
        # scans (check, analyze), using gitignore syntax relative to this file.

        # Dependencies you do not control
        vendor/
        node_modules/

        # Generated and built assets
        public/build/
        dist/

        # Favicons and touch icons: tiny and format-constrained, not worth optimizing
        favicon.ico
        favicon*.png
        apple-touch-icon*.png

        GITIGNORE;

    /**
     * Whether this run recorded the current images into the baseline (the
     * seed scan ran and succeeded, or the baseline already existed), which
     * drops the "accept the current images" next-steps hint.
     */
    private bool $baselinePopulated = false;

    public function handle(): int
    {
        return $this->runGuarded(function () {
            // The console application reuses the resolved command instance,
            // so the flag must not carry over from an earlier in-process run.
            $this->baselinePopulated = false;

            $root = Paths::root();

            // Order is load-bearing: the ignore file must exist before any
            // seed scan, because ImageFinder honors it; otherwise vendor/
            // images get recorded into the seeded baseline.
            $this->writeIgnoreFile($root);

            $exitCode = $this->setUpBaseline($root);

            $this->printNextSteps();

            return $exitCode;
        });
    }

    private function writeIgnoreFile(string $root): void
    {
        $path = $root.'/'.IgnoreFile::FILENAME;
        $exists = is_file($path);

        if ($exists && ! $this->option('force')) {
            $this->line(IgnoreFile::FILENAME.' already exists, kept (use --force to recreate it from the starter template).');

            return;
        }

        // Exclusive creation when the file was absent: one that appeared
        // since the check above fails the write instead of being truncated.
        $handle = @fopen($path, $exists ? 'w' : 'x');

        if ($handle === false) {
            throw new ApiException("Could not write {$path}.");
        }

        $written = @fwrite($handle, self::IGNORE_TEMPLATE);
        fclose($handle);

        if ($written !== strlen(self::IGNORE_TEMPLATE)) {
            if (! $exists) {
                @unlink($path);
            }

            throw new ApiException("Could not write {$path}.");
        }

        $this->info($exists
            ? 'Recreated '.IgnoreFile::FILENAME.' from the starter template.'
            : 'Created '.IgnoreFile::FILENAME.'.');
    }

    /**
     * Create or seed the baseline. Seeding is opt-in: the --update-baseline
     * flag, or an interactive confirm defaulting to No, so a plain init
     * works with zero prerequisites (no token, no network). confirm()
     * returns the default when the input is non-interactive (-n) or stdin
     * is at end-of-file, the usual CI shape, so those runs fall through to
     * the empty baseline with zero API calls. Piped input that carries an
     * answer is read as that answer.
     */
    private function setUpBaseline(string $root): int
    {
        $path = $root.'/'.BaselineFile::FILENAME;
        $exists = is_file($path);

        $seed = (bool) $this->option('update-baseline');

        if (! $seed && ! $exists) {
            $seed = $this->confirm('Scan the current directory and record every image into the baseline now (runs analyze . --update-baseline)?', false);
        }

        if ($seed) {
            return $this->seedBaseline($path);
        }

        if ($exists) {
            $this->baselinePopulated = true;
            $this->line(BaselineFile::FILENAME.' already exists, kept.');

            return self::SUCCESS;
        }

        BaselineFile::load($root)->save($root);
        $this->info('Created '.BaselineFile::FILENAME.' (empty).');

        return self::SUCCESS;
    }

    /**
     * Seed by delegating to analyze, reusing its locking, hashing, pruning,
     * and reporting; its exit code is passed through. A failed seed that
     * aborted before the baseline was saved still leaves an empty baseline
     * behind, so the project ends up scaffolded either way, but the run
     * fails because a requested seed did not happen.
     */
    private function seedBaseline(string $path): int
    {
        $exitCode = $this->call('analyze', ['input' => '.', '--update-baseline' => true]);

        if ($exitCode === self::SUCCESS) {
            $this->baselinePopulated = true;

            return self::SUCCESS;
        }

        if (! is_file($path)) {
            BaselineFile::load(dirname($path))->save(dirname($path));
            $this->warn('The scan failed; wrote an empty '.BaselineFile::FILENAME.' instead. After fixing the problem, run: glimpse analyze . --update-baseline');
        }

        return self::FAILURE;
    }

    private function printNextSteps(): void
    {
        $steps = ['Review '.IgnoreFile::FILENAME.' and tune the patterns for your project.'];

        if (! $this->baselinePopulated) {
            $steps[] = 'Accept the current images as already handled: glimpse analyze . --update-baseline';
        }

        $steps[] = 'Commit '.IgnoreFile::FILENAME.' and '.BaselineFile::FILENAME.'.';
        $steps[] = 'Gate new images in CI: glimpse check .  (see the Continuous Integration section of the README)';

        $this->newLine();
        $this->line('Next steps:');

        foreach ($steps as $index => $step) {
            $this->line('  '.($index + 1).'. '.$step);
        }
    }
}
