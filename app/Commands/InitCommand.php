<?php

namespace App\Commands;

use App\Commands\Concerns\GuardsApiErrors;
use App\Support\BaselineFile;
use App\Support\IgnoreFile;
use App\Support\Paths;
use App\Support\ScaffoldFile;
use LaravelZero\Framework\Commands\Command;

class InitCommand extends Command
{
    use GuardsApiErrors;

    protected $signature = 'init
        {--update-baseline : Seed the baseline by scanning the current directory (runs analyze . --update-baseline)}
        {--workflow : Add a GitHub Actions workflow that runs glimpse check, without prompting}
        {--force : Recreate .glimpseignore from its template even when it exists; add --workflow to also recreate the workflow file}';

    protected $description = 'Set up the current directory for glimpse: a starter .glimpseignore, the baseline, and optionally a CI workflow';

    /**
     * Where the scaffolded GitHub Actions workflow lives, relative to
     * the project root.
     */
    public const WORKFLOW_PATH = '.github/workflows/glimpse.yml';

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

        # Runtime state and user uploads (Laravel)
        storage/

        # Favicons and touch icons: tiny and format-constrained, not worth optimizing
        favicon.ico
        favicon*.png
        apple-touch-icon*.png

        GITIGNORE;

    /**
     * The scaffolded GitHub Actions workflow. A nowdoc, because the
     * YAML carries ${{ ... }} expressions a heredoc would interpolate.
     * The continuous-integration page of the docs (glimpseimg.com/docs)
     * shows this template as the copy-by-hand alternative; update it
     * when the template changes. The glimpseimg.com repo runs a
     * docs-drift workflow that compares the two and fails on mismatch.
     *
     * The install goes through Composer so every CI run is counted as a
     * Packagist install. The package declares no runtime dependencies
     * (the bin is the committed phar), so the install only downloads the
     * package itself; no cache step is worth the extra YAML.
     *
     * The check step is guarded on the token (mapped into the job env,
     * because the secrets context is not available in step-level if
     * expressions): fork pull requests never receive repository
     * secrets, so those runs skip the check with a visible warning
     * instead of failing on an authentication error, and the push run
     * after the merge still gates with the real secret.
     */
    public const WORKFLOW_TEMPLATE = <<<'YAML'
        name: Glimpse

        on:
          push:
            branches: [main]
          pull_request:

        permissions:
          contents: read

        jobs:
          check-images:
            runs-on: ubuntu-latest
            env:
              GLIMPSE_TOKEN: ${{ secrets.GLIMPSE_TOKEN }}
            steps:
              - uses: actions/checkout@v6
              - name: Install glimpse
                run: |
                  composer global require --no-interaction --no-progress mathiasgrimm/glimpse-cli
                  composer global config bin-dir --absolute --quiet >> "$GITHUB_PATH"
              - name: Check images
                if: env.GLIMPSE_TOKEN != ''
                run: glimpse check .
              - name: Skipped (no token)
                if: env.GLIMPSE_TOKEN == ''
                run: echo "::warning::GLIMPSE_TOKEN is not available (fork PR, or the secret is not set); image check skipped."

        YAML;

    /**
     * Whether this run wrote the workflow file (created or recreated),
     * and whether it found and kept an existing one. Together with
     * "neither" they pick the next-steps variant.
     */
    private bool $workflowWritten = false;

    private bool $workflowKept = false;

    public function handle(): int
    {
        return $this->runGuarded(function () {
            // The console application reuses the resolved command instance,
            // so the flags must not carry over from an earlier in-process run.
            $this->workflowWritten = false;
            $this->workflowKept = false;

            $root = Paths::root();

            // Order is load-bearing: the ignore file must exist before any
            // seed scan, because ImageFinder honors it; otherwise vendor/
            // images get recorded into the seeded baseline.
            $this->writeIgnoreFile($root);

            $exitCode = $this->setUpBaseline($root);

            $this->setUpWorkflow($root);

            // The empty-baseline warning and the seed hint key on what is
            // actually on disk after the run, not on what this run did: a
            // baseline kept from an earlier run may itself be empty, and a
            // failed refresh leaves a populated baseline intact.
            $this->printNextSteps(baselineEmpty: BaselineFile::load($root)->count() === 0);

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

        ScaffoldFile::write($root, IgnoreFile::FILENAME, self::IGNORE_TEMPLATE, replace: $exists);

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
     *
     * The dangerous shape is an empty baseline COMBINED with the workflow:
     * the CI gate then re-checks every image already in the repository and
     * fails on any above the check threshold. That combination happens on
     * every scripted `init --workflow` run, because the seed confirm
     * silently falls through to No, so printNextSteps() warns loudly when
     * the run ends in that state. Emptiness is read from the file on disk,
     * not from what this run did: a kept baseline may itself be empty, and
     * a failed refresh leaves a populated baseline intact.
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
            return self::SUCCESS;
        }

        if (! is_file($path)) {
            BaselineFile::load(dirname($path))->save(dirname($path));
            $this->warn('The scan failed; wrote an empty '.BaselineFile::FILENAME.' instead. After fixing the problem, run: glimpse analyze . --update-baseline');
        }

        return self::FAILURE;
    }

    /**
     * Scaffold the GitHub Actions workflow. Opt-in like the seed scan:
     * the --workflow flag selects it outright; a plain run only offers
     * it (a confirm defaulting to No) when the file is missing and the
     * root is a git repository, so non-interactive runs never write it
     * and non-repos are never asked. An existing file is always kept
     * unless --workflow --force replaces it; --force alone never
     * touches it, because --workflow selects the step and --force is
     * only the overwrite modifier.
     */
    private function setUpWorkflow(string $root): void
    {
        $path = $root.'/'.self::WORKFLOW_PATH;
        $selected = (bool) $this->option('workflow');

        if (is_file($path)) {
            if ($selected && $this->option('force')) {
                ScaffoldFile::write($root, self::WORKFLOW_PATH, self::WORKFLOW_TEMPLATE, replace: true);
                $this->info('Recreated '.self::WORKFLOW_PATH.' from the starter template.');
                $this->workflowWritten = true;

                return;
            }

            $this->line(self::WORKFLOW_PATH.' already exists, kept (use --workflow --force to recreate it).');
            $this->workflowKept = true;

            return;
        }

        if (! $selected && $this->isGitRoot($root)) {
            $selected = $this->confirm('Add a GitHub Actions workflow that runs glimpse check on pull requests and pushes to main ('.self::WORKFLOW_PATH.')?', false);
        }

        if (! $selected) {
            return;
        }

        ScaffoldFile::write($root, self::WORKFLOW_PATH, self::WORKFLOW_TEMPLATE);
        $this->info('Created '.self::WORKFLOW_PATH.'.');
        $this->workflowWritten = true;
    }

    /**
     * Whether the root is a git repository: .git is a directory in a
     * normal clone and a file in a worktree.
     */
    private function isGitRoot(string $root): bool
    {
        return file_exists($root.'/.git');
    }

    private function printNextSteps(bool $baselineEmpty): void
    {
        if ($baselineEmpty && ($this->workflowWritten || $this->workflowKept)) {
            $this->newLine();
            $this->warn('The baseline is empty but the workflow gates images, so the first CI run will re-check every image in the repository and fail on any that would benefit from optimization. Seed the baseline before pushing: glimpse analyze . --update-baseline');
        }

        $steps = ['Review '.IgnoreFile::FILENAME.' and tune the patterns for your project.'];

        if ($baselineEmpty) {
            $steps[] = 'Accept the current images as already handled: glimpse analyze . --update-baseline';
        }

        if ($this->workflowWritten) {
            $steps[] = 'Set the GLIMPSE_TOKEN secret on the repository: gh secret set GLIMPSE_TOKEN';
            $steps[] = 'Commit '.IgnoreFile::FILENAME.', '.BaselineFile::FILENAME.', and '.self::WORKFLOW_PATH.'.';
        } else {
            $steps[] = 'Commit '.IgnoreFile::FILENAME.' and '.BaselineFile::FILENAME.'.';

            $steps[] = $this->workflowKept
                ? 'Review '.self::WORKFLOW_PATH.' and make sure the GLIMPSE_TOKEN secret is set: gh secret set GLIMPSE_TOKEN'
                : 'Gate new images in CI: glimpse check .  (see https://glimpseimg.com/docs/cli/continuous-integration)';
        }

        $this->newLine();
        $this->line('Next steps:');

        foreach ($steps as $index => $step) {
            $this->line('  '.($index + 1).'. '.$step);
        }
    }
}
