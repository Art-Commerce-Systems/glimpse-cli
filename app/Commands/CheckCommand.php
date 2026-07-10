<?php

namespace App\Commands;

use App\Commands\Concerns\EstimatesImages;
use App\Glimpse\ApiException;
use App\Glimpse\Client;
use App\Glimpse\SampleProbe;
use App\Support\ImageFinder;

class CheckCommand extends GlimpseCommand
{
    use EstimatesImages;

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

            if ($files === []) {
                $this->option('json')
                    ? $this->emitJson([], [], 0, $threshold)
                    : $this->info("No images found in {$input}.");

                return self::SUCCESS;
            }

            $bar = $this->option('json') ? null : $this->output->createProgressBar(count($files));
            $bar?->start();

            $rows = [];

            foreach ($files as $path) {
                $rows[] = $this->estimateFile($client, $probe, $dir, $path, target: null, quality: null);
                $bar?->advance();
            }

            $bar?->finish();
            $bar?->clear();

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
                ? $this->emitJson($offenders, $failed, count($rows), $threshold)
                : $this->render($offenders, $failed, count($rows), $threshold);

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
    private function render(array $offenders, array $failed, int $total, float $threshold): void
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
                '%d of %d images need optimization (threshold: %s%%).',
                count($offenders),
                $total,
                $percent,
            ));
        } else {
            $this->info("All {$total} images are within the {$percent}% threshold.");
        }

        if ($failed !== []) {
            $this->newLine();
            $this->error(count($failed).' file(s) could not be checked:');

            foreach ($failed as $row) {
                $this->line("  <fg=red>{$row['file']}</>: {$row['error']}");
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $offenders
     * @param  list<array<string, mixed>>  $failed
     */
    private function emitJson(array $offenders, array $failed, int $total, float $threshold): void
    {
        $this->line((string) json_encode([
            'threshold' => $threshold,
            'total' => $total,
            'needs_optimization' => count($offenders),
            'files' => $offenders,
            'failed' => $failed,
        ], JSON_UNESCAPED_SLASHES));
    }
}
