<?php

namespace App\Commands;

use App\Enums\ImageFormat;
use App\Glimpse\ApiException;
use App\Glimpse\AuthException;
use App\Glimpse\Client;
use App\Glimpse\SampleProbe;
use App\Support\ImageFinder;
use Symfony\Component\Console\Helper\TableSeparator;

class EstimateCommand extends GlimpseCommand
{
    protected $signature = 'estimate
        {input : Path to an image or a directory to scan recursively, or - for stdin}
        {--format= : Only show estimates for this target format (jpg, png, webp, gif, avif)}
        {--optimize : Assume the optimizer chain runs on the re-encode}
        {--quality= : Assumed re-encode quality 1-100, perceptual scale; requires --optimize (defaults to 85)}
        {--json : Print the estimates as JSON}';

    protected $description = 'Estimate converted sizes without uploading the image';

    public function handle(Client $client, SampleProbe $probe): int
    {
        return $this->runGuarded(function () use ($client, $probe) {
            $input = $this->inputArgument();
            $target = $this->resolveFormat();
            $quality = $this->intOption('quality');

            if ($quality !== null && ! $this->option('optimize')) {
                throw new ApiException('--quality requires --optimize.');
            }

            return is_dir($input)
                ? $this->handleDirectory($client, $probe, $input, $target, $quality)
                : $this->handleFile($client, $probe, $input, $target, $quality);
        });
    }

    private function handleFile(Client $client, SampleProbe $probe, string $input, ?ImageFormat $target, ?int $quality): int
    {
        $bytes = $this->readImage($input, limitBytes: false);

        $format = ImageFormat::tryFromBinary($bytes)
            ?? throw new ApiException('Unrecognized image format. Supported: jpg, png, webp, gif, avif.');

        [$width, $height, $sampleBpp] = $this->measure($probe, $bytes);

        $estimates = $client->estimate($format, strlen($bytes), $width, $height, $quality, $sampleBpp);

        if ($target !== null) {
            $estimates = [$this->pick($estimates, $target)
                ?? throw new ApiException('No estimate for '.strtoupper($target->value).'.'), ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($estimates, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($format, strlen($bytes), $width, $height, $sampleBpp, $estimates);

        return self::SUCCESS;
    }

    private function handleDirectory(Client $client, SampleProbe $probe, string $dir, ?ImageFormat $target, ?int $quality): int
    {
        $files = (new ImageFinder)->find($dir);

        if ($files === []) {
            throw new ApiException("No image files found in {$dir}.");
        }

        $bar = $this->option('json') ? null : $this->output->createProgressBar(count($files));
        $bar?->start();

        $rows = [];

        foreach ($files as $path) {
            $rows[] = $this->estimateFile($client, $probe, $dir, $path, $target, $quality);
            $bar?->advance();
        }

        $bar?->finish();
        $bar?->clear();

        if ($bar !== null) {
            $this->newLine();
        }

        $this->option('json') ? $this->emitBatchJson($rows) : $this->renderBatch($rows);

        $failed = count(array_filter($rows, fn (array $row) => isset($row['error'])));

        return $failed < count($rows) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Estimate a single file inside a batch. Failures are recorded, not
     * thrown, so one bad file does not abort the scan. Auth failures do
     * abort: they would fail every remaining file the same way.
     *
     * @return array<string, mixed>
     */
    private function estimateFile(Client $client, SampleProbe $probe, string $dir, string $path, ?ImageFormat $target, ?int $quality): array
    {
        $file = ltrim(substr($path, strlen(rtrim($dir, '/'))), '/');

        try {
            $bytes = $this->readImage($path, limitBytes: false);

            $format = ImageFormat::tryFromBinary($bytes)
                ?? throw new ApiException('Unrecognized image format.');

            [$width, $height, $sampleBpp] = $this->measure($probe, $bytes);

            $estimates = $client->estimate($format, strlen($bytes), $width, $height, $quality, $sampleBpp);

            $pick = $this->pick($estimates, $target) ?? throw new ApiException(
                $target === null ? 'No estimates returned.' : 'No estimate for '.strtoupper($target->value).'.',
            );

            return ['file' => $file, 'source_format' => $format->value, 'source_size' => strlen($bytes)] + $pick;
        } catch (AuthException $exception) {
            throw $exception;
        } catch (ApiException $exception) {
            return ['file' => $file, 'error' => $exception->getMessage()];
        }
    }

    private function resolveFormat(): ?ImageFormat
    {
        $option = $this->option('format');

        if (! is_string($option) || $option === '') {
            return null;
        }

        return ImageFormat::tryFrom(strtolower($option))
            ?? throw new ApiException("Unsupported format: {$option}. Supported: jpg, png, webp, gif, avif.");
    }

    /**
     * Pick the estimate to report for one image: the target format's entry
     * when --format is set, otherwise the one that saves the most. The
     * source format never wins "best": the API reports it with a negative
     * saving.
     *
     * @param  list<array<string, mixed>>  $estimates
     * @return array<string, mixed>|null
     */
    private function pick(array $estimates, ?ImageFormat $target): ?array
    {
        if ($target !== null) {
            foreach ($estimates as $estimate) {
                if (data_get($estimate, 'format') === $target->value) {
                    return $estimate;
                }
            }

            return null;
        }

        $best = null;

        foreach ($estimates as $estimate) {
            $saved = data_get($estimate, 'saved');

            if (is_int($saved) && ($best === null || $saved > data_get($best, 'saved'))) {
                $best = $estimate;
            }
        }

        return $best;
    }

    /**
     * Probe the bytes for dimensions and a complexity sample, falling back
     * to a plain dimension read when no image extension can decode them.
     *
     * @return array{?int, ?int, ?float}
     */
    private function measure(SampleProbe $probe, string $bytes): array
    {
        $result = $probe->measure($bytes);

        if ($result !== null) {
            return [$result->width, $result->height, $result->sampleBpp];
        }

        [$width, $height] = $this->dimensions($bytes);

        return [$width, $height, null];
    }

    /**
     * Fallback when no image extension can decode the bytes: read the
     * pixel dimensions without a complexity sample. Returns nulls when
     * PHP cannot parse the format, which degrades the estimates to
     * size-ratio heuristics.
     *
     * @return array{?int, ?int}
     */
    private function dimensions(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false) {
            return [null, null];
        }

        return [$info[0], $info[1]];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderBatch(array $rows): void
    {
        $tableRows = array_map(function (array $row) {
            if (isset($row['error'])) {
                return [$row['file'], "<fg=red>skipped: {$row['error']}</>", '-', '-', '-', '-'];
            }

            $size = data_get($row, 'size');
            $savedPercent = data_get($row, 'saved_percent');

            return [
                $row['file'],
                strtoupper((string) $row['source_format']).', '.$this->humanSize((int) $row['source_size']),
                is_string(data_get($row, 'format')) ? strtoupper((string) data_get($row, 'format')) : '?',
                is_int($size) ? '~'.$this->humanSize($size) : '?',
                $this->formatSaved(data_get($row, 'saved')),
                is_numeric($savedPercent) ? $savedPercent.'%' : '?',
            ];
        }, $rows);

        $totals = $this->totals($rows);

        $tableRows[] = new TableSeparator;
        $tableRows[] = [
            sprintf('Total: %d files%s', $totals['files'], $totals['failed'] > 0 ? ", {$totals['failed']} failed" : ''),
            $this->humanSize($totals['source_size']),
            '-',
            '~'.$this->humanSize($totals['estimated_size']),
            $this->formatSaved($totals['saved']),
            $totals['saved_percent'] === null ? '-' : $totals['saved_percent'].'%',
        ];

        $this->table(['File', 'Source', 'Format', 'Estimated', 'Saved', 'Saved %'], $tableRows);

        $this->line('<fg=gray>Estimates are heuristics for picking a target format, not guarantees.</>');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function emitBatchJson(array $rows): void
    {
        $this->line((string) json_encode([
            'files' => $rows,
            'totals' => $this->totals($rows),
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Sum the successful rows into the totalizer. Failed files count
     * toward `files` and `failed` but not toward the byte totals.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{files: int, failed: int, source_size: int, estimated_size: int, saved: int, saved_percent: float|null}
     */
    private function totals(array $rows): array
    {
        $sourceSize = 0;
        $estimatedSize = 0;
        $failed = 0;

        foreach ($rows as $row) {
            if (isset($row['error'])) {
                $failed++;

                continue;
            }

            $sourceSize += is_int($row['source_size'] ?? null) ? $row['source_size'] : 0;
            $estimatedSize += is_int($row['size'] ?? null) ? $row['size'] : 0;
        }

        $saved = $sourceSize - $estimatedSize;

        return [
            'files' => count($rows),
            'failed' => $failed,
            'source_size' => $sourceSize,
            'estimated_size' => $estimatedSize,
            'saved' => $saved,
            'saved_percent' => $sourceSize > 0 ? round($saved / $sourceSize * 100, 1) : null,
        ];
    }

    private function formatSaved(mixed $saved): string
    {
        return is_int($saved) ? ($saved < 0 ? '-' : '').$this->humanSize(abs($saved)) : '?';
    }

    /**
     * @param  list<array<string, mixed>>  $estimates
     */
    private function render(ImageFormat $format, int $size, ?int $width, ?int $height, ?float $sampleBpp, array $estimates): void
    {
        $source = strtoupper($format->value).', '.$this->humanSize($size);

        if ($width !== null && $height !== null) {
            $source .= ", {$width}x{$height}";
        }

        if ($sampleBpp !== null) {
            $source .= ', sampled';
        }

        $this->line("<options=bold>Source</>: {$source}");
        $this->newLine();

        $rows = array_map(function (array $estimate) {
            $estimatedSize = data_get($estimate, 'size');
            $savedPercent = data_get($estimate, 'saved_percent');
            $quality = data_get($estimate, 'quality');

            return [
                is_string(data_get($estimate, 'format')) ? strtoupper((string) data_get($estimate, 'format')) : '?',
                is_int($estimatedSize) ? '~'.$this->humanSize($estimatedSize) : '?',
                $this->formatSaved(data_get($estimate, 'saved')),
                is_numeric($savedPercent) ? $savedPercent.'%' : '?',
                $quality === null ? '-' : (string) $quality,
            ];
        }, $estimates);

        $this->table(['Format', 'Estimated size', 'Saved', 'Saved %', 'Quality'], $rows);

        $this->line('<fg=gray>Estimates are heuristics for picking a target format, not guarantees.</>');
    }
}
