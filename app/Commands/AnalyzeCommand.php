<?php

namespace App\Commands;

use App\Commands\Concerns\AnalyzesImages;
use App\Enums\ImageFormat;
use App\Glimpse\ApiException;
use App\Glimpse\Client;
use App\Glimpse\SampleProbe;
use App\Support\ImageFinder;
use Symfony\Component\Console\Helper\TableSeparator;

class AnalyzeCommand extends GlimpseCommand
{
    use AnalyzesImages;

    protected $signature = 'analyze
        {input : Path to an image or a directory to scan recursively, or - for stdin}
        {--format= : Only show estimates for this target format (jpg, png, webp, gif, avif)}
        {--optimize : Assume the optimizer chain runs on the re-encode}
        {--quality= : Assumed re-encode quality 1-100, perceptual scale; requires --optimize (defaults to 85)}
        {--json : Print the estimates as JSON}';

    protected $description = 'Analyze converted sizes without uploading the image';

    /**
     * How many summary rows to print between repeated header rows, so
     * the columns stay identifiable on long listings. One classic
     * 24-row terminal screenful.
     */
    private const HEADER_EVERY = 24;

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

        $estimates = $client->analyze($format, strlen($bytes), $width, $height, $quality, $sampleBpp);

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
            $rows[] = $this->analyzeFile($client, $probe, $dir, $path, $target, $quality);
            $bar?->advance();
        }

        $bar?->finish();
        $bar?->clear();

        if ($bar !== null) {
            $this->newLine();
        }

        $rows = $this->sortBySaved($rows);

        $this->option('json') ? $this->emitBatchJson($rows) : $this->renderBatch($rows);

        $failed = count(array_filter($rows, fn (array $row) => isset($row['error'])));

        return $failed < count($rows) ? self::SUCCESS : self::FAILURE;
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
     * Classify a row for the summary: green saves at least a quarter,
     * yellow saves less than that, red would grow the file.
     *
     * @param  array<string, mixed>  $row
     */
    private function rowColor(array $row): string
    {
        $saved = data_get($row, 'saved');

        if (! is_int($saved)) {
            return 'yellow';
        }

        if ($saved < 0) {
            return 'red';
        }

        $percent = data_get($row, 'saved_percent');

        return is_numeric($percent) && $percent >= 25 ? 'green' : 'yellow';
    }

    /**
     * @param  list<string>  $cells
     * @return list<string>
     */
    private function colorize(array $cells, string $color): array
    {
        return array_map(fn (string $cell) => "<fg={$color}>{$cell}</>", $cells);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderBatch(array $rows): void
    {
        $headers = ['File', 'Source', 'Format', 'Estimated', 'Saved', 'Saved %'];

        $tableRows = [];

        foreach ($rows as $index => $row) {
            if ($index > 0 && $index % self::HEADER_EVERY === 0) {
                $tableRows[] = new TableSeparator;
                $tableRows[] = array_map(fn (string $header) => "<info>{$header}</info>", $headers);
                $tableRows[] = new TableSeparator;
            }

            if (isset($row['error'])) {
                $tableRows[] = $this->colorize([$row['file'], "skipped: {$row['error']}", '-', '-', '-', '-'], 'red');

                continue;
            }

            $size = data_get($row, 'size');
            $savedPercent = data_get($row, 'saved_percent');

            $tableRows[] = $this->colorize([
                $row['file'],
                strtoupper((string) $row['source_format']).', '.$this->humanSize((int) $row['source_size']),
                is_string(data_get($row, 'format')) ? strtoupper((string) data_get($row, 'format')) : '?',
                is_int($size) ? '~'.$this->humanSize($size) : '?',
                $this->formatSaved(data_get($row, 'saved')),
                is_numeric($savedPercent) ? $savedPercent.'%' : '?',
            ], $this->rowColor($row));
        }

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
