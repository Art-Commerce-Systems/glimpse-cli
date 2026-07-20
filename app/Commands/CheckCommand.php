<?php

namespace App\Commands;

use App\Commands\Concerns\AnalyzesImages;
use App\Support\BaselineFile;
use App\Support\ImageFinder;
use App\Support\Paths;
use GlimpseImg\ApiException;
use GlimpseImg\Client;
use GlimpseImg\SampleProbe;
use Illuminate\Support\Str;

class CheckCommand extends GlimpseCommand
{
    use AnalyzesImages;

    protected $signature = 'check
        {input : Path to an image or a directory to scan recursively}
        {--threshold=10 : Flag images whose best-format estimated saving is at least this percent (0-100)}
        {--json : Print the results as JSON}';

    protected $description = 'Fail when images would benefit from optimization; built for CI';

    public function handle(Client $client, SampleProbe $probe): int
    {
        return $this->runGuarded(function () use ($client, $probe) {
            $threshold = $this->threshold();
            $input = $this->inputArgument();

            [$dir, $files] = $this->collect($input);

            $skipped = 0;

            if (is_dir($input)) {
                $root = Paths::root();
                [$files, $skipped] = $this->partitionByBaseline(BaselineFile::load($root), $root, $input, $files);
            }

            if ($files === []) {
                $this->option('json')
                    ? $this->emitJson([], [], 0, $threshold, $skipped)
                    : $this->info($skipped > 0
                        ? $this->allCoveredMessage($skipped)
                        : "No images found in {$input}.");

                return self::SUCCESS;
            }

            $bar = $this->option('json') ? null : $this->output->createProgressBar(count($files));
            $bar?->start();

            $rows = [];

            try {
                foreach ($files as $path) {
                    $rows[] = $this->analyzeFile($client, $probe, $dir, $path, target: null, quality: null);
                    $bar?->advance();
                }

                $bar?->finish();
            } finally {
                // An aborting exception (auth, rate limit, forbidden) must
                // not leave a half-rendered bar under the error output.
                $bar?->clear();
            }

            if ($bar !== null) {
                $this->newLine();
            }

            $failed = array_values(array_filter($rows, fn (array $row) => isset($row['error'])));
            $offenders = $this->sortBySaved(array_values(array_filter(
                $rows,
                fn (array $row) => ! isset($row['error'])
                    && is_numeric($row['saved_percent'] ?? null)
                    && $row['saved_percent'] >= $threshold,
            )));

            $this->option('json')
                ? $this->emitJson($offenders, $failed, count($rows), $threshold, $skipped)
                : $this->render($offenders, $failed, count($rows), $threshold, $skipped);

            return $offenders === [] && $failed === [] ? self::SUCCESS : self::FAILURE;
        });
    }

    /**
     * Resolve the scan root and the files to check. A directory is
     * scanned recursively (honoring .glimpseignore); a single file is
     * checked as-is, displayed by its basename.
     *
     * @return array{string, list<string>}
     */
    private function collect(string $input): array
    {
        if (is_dir($input)) {
            return [$input, (new ImageFinder)->find($input)];
        }

        if (is_file($input)) {
            return [dirname($input), [$input]];
        }

        throw new ApiException("File not found: {$input}");
    }

    private function threshold(): float
    {
        $value = $this->option('threshold');

        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            throw new ApiException('The --threshold option must be a number between 0 and 100.');
        }

        return (float) $value;
    }

    /**
     * @param  list<array<string, mixed>>  $offenders
     * @param  list<array<string, mixed>>  $failed
     */
    private function render(array $offenders, array $failed, int $total, float $threshold, int $baselineSkipped): void
    {
        $percent = rtrim(rtrim(number_format($threshold, 1), '0'), '.');

        if ($offenders !== []) {
            $this->table(['File', 'Current', 'Estimated', 'Format', 'Saved %'], array_map(fn (array $row) => [
                $row['file'],
                $this->humanSize((int) $row['source_size']),
                is_int($row['size'] ?? null) ? '~'.$this->humanSize($row['size']) : '?',
                is_string($row['format'] ?? null) ? strtoupper((string) $row['format']) : '?',
                is_numeric($row['saved_percent'] ?? null) ? $row['saved_percent'].'%' : '?',
            ], $offenders));

            $this->error(sprintf(
                '%d of %d %s %s optimization (threshold: %s%%).',
                count($offenders),
                $total,
                Str::plural('image', $total),
                count($offenders) === 1 ? 'needs' : 'need',
                $percent,
            ));
        } else {
            $this->info($total === 1
                ? "The 1 image is within the {$percent}% threshold."
                : "All {$total} images are within the {$percent}% threshold.");
        }

        if ($failed !== []) {
            $this->newLine();
            $this->error(sprintf('%d %s could not be checked:', count($failed), Str::plural('file', count($failed))));

            foreach ($failed as $row) {
                $this->line("  <fg=red>{$row['file']}</>: {$row['error']}");
            }
        }

        if ($baselineSkipped > 0) {
            $this->line($this->baselineSkippedLine($baselineSkipped));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $offenders
     * @param  list<array<string, mixed>>  $failed
     */
    private function emitJson(array $offenders, array $failed, int $total, float $threshold, int $baselineSkipped): void
    {
        $this->line((string) json_encode([
            'threshold' => $threshold,
            'total' => $total,
            'needs_optimization' => count($offenders),
            'files' => $offenders,
            'failed' => $failed,
            'baseline_skipped' => $baselineSkipped,
        ], JSON_UNESCAPED_SLASHES));
    }
}
