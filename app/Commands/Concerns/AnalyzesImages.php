<?php

namespace MathiasGrimm\GlimpseCli\Commands\Concerns;

use Closure;
use Illuminate\Support\Str;
use MathiasGrimm\GlimpseCli\Support\BaselineFile;
use MathiasGrimm\GlimpseCli\Support\Paths;
use MathiasGrimm\GlimpseCli\Support\Sleeper;
use MathiasGrimm\GlimpsePhp\ApiException;
use MathiasGrimm\GlimpsePhp\AuthException;
use MathiasGrimm\GlimpsePhp\Client;
use MathiasGrimm\GlimpsePhp\ForbiddenException;
use MathiasGrimm\GlimpsePhp\FrameCounter;
use MathiasGrimm\GlimpsePhp\ImageFormat;
use MathiasGrimm\GlimpsePhp\RateLimitException;
use MathiasGrimm\GlimpsePhp\SampleProbe;
use MathiasGrimm\GlimpsePhp\SizeEstimate;

trait AnalyzesImages
{
    private const RATE_LIMIT_MAX_RETRIES = 3;

    private const RATE_LIMIT_DEFAULT_DELAY_SECONDS = 5;

    private const RATE_LIMIT_MAX_DELAY_SECONDS = 60;

    /**
     * Size and content hash of each file this run analyzed, captured from
     * the exact bytes that were measured, so --update-baseline records
     * what was analyzed without re-reading (or trusting) the disk after
     * the network-bound batch finished.
     *
     * @var array<string, array{size: int, xxh128: string}>
     */
    private array $analyzedHashes = [];

    /**
     * Analyze a single file inside a batch. Failures are recorded, not
     * thrown, so one bad file does not abort the scan. Auth failures do
     * abort: they would fail every remaining file the same way.
     *
     * @return array<string, mixed>
     */
    private function analyzeFile(Client $client, SampleProbe $probe, string $dir, string $path, ?ImageFormat $target, ?int $quality): array
    {
        $file = Paths::relativePath($dir, $path);

        try {
            $bytes = $this->readImage($path, limitBytes: false);

            if ($this->hasOption('update-baseline') && (bool) $this->option('update-baseline')) {
                $this->analyzedHashes[$file] = ['size' => strlen($bytes), 'xxh128' => hash('xxh128', $bytes)];
            }

            $format = ImageFormat::tryFromBinary($bytes)
                ?? throw new ApiException('Unrecognized image format.');

            [$width, $height, $sampleBpp] = $this->measure($probe, $bytes);

            $estimates = $this->estimateRows($this->analyzeWithRetry(
                fn (): array => $client->analyze($format, strlen($bytes), $width, $height, $quality, $sampleBpp, $this->frames($bytes)),
            ));

            $pick = $this->pick($estimates, $target) ?? throw new ApiException(
                $target === null ? 'No estimates returned.' : 'No estimate for '.strtoupper($target->value).'.',
            );

            return ['file' => $file, 'source_format' => $format->value, 'source_size' => strlen($bytes)] + $pick;
        } catch (AuthException $exception) {
            throw $exception;
        } catch (RateLimitException|ForbiddenException $exception) {
            // Retries are exhausted, or the token may not analyze at all;
            // every remaining file would fail the same way, so abort the
            // batch instead of burning through it and recording the same
            // per-file error over and over.
            throw $exception;
        } catch (ApiException $exception) {
            return ['file' => $file, 'error' => $exception->getMessage()];
        }
    }

    /**
     * Run one analyze call, riding out brief rate limiting: wait out the
     * Retry-After delay for a few attempts, then give up and let the
     * rate limit exception propagate. A delay beyond the cap means the
     * limit window outlives any sane retry budget (retrying inside it is
     * a guaranteed 429), so that gives up right away.
     *
     * @param  Closure(): list<SizeEstimate>  $call
     * @return list<SizeEstimate>
     */
    private function analyzeWithRetry(Closure $call): array
    {
        $retries = 0;

        while (true) {
            try {
                return $call();
            } catch (RateLimitException $exception) {
                $delay = $exception->retryAfterSeconds ?? self::RATE_LIMIT_DEFAULT_DELAY_SECONDS;

                if ($retries++ >= self::RATE_LIMIT_MAX_RETRIES || $delay > self::RATE_LIMIT_MAX_DELAY_SECONDS) {
                    throw $exception;
                }

                // Stderr, so --json consumers reading stdout stay parseable,
                // and the wait never looks like a hang.
                fwrite(STDERR, sprintf('Rate limited; retrying in %ds.%s', $delay, PHP_EOL));

                app(Sleeper::class)->sleep($delay);
            }
        }
    }

    /**
     * Split the found files into the ones still to analyze and the count
     * covered by the baseline. A scan directory outside the root is left
     * untouched: no file gets skipped.
     *
     * @param  list<string>  $files
     * @return array{list<string>, int}
     */
    private function partitionByBaseline(BaselineFile $baseline, string $root, string $dir, array $files): array
    {
        $prefix = Paths::keyPrefix($root, $dir);

        if ($prefix === null) {
            return [$files, 0];
        }

        $remaining = array_values(array_filter(
            $files,
            fn (string $path) => ! $baseline->skips($prefix.Paths::relativePath($dir, $path), $path),
        ));

        return [$remaining, count($files) - count($remaining)];
    }

    /**
     * The summary line for a scan the baseline fully absorbed.
     */
    private function allCoveredMessage(int $count): string
    {
        return $count === 1
            ? 'The 1 image is covered by the baseline.'
            : "All {$count} images are covered by the baseline.";
    }

    /**
     * The gray note counting files the baseline skipped.
     */
    private function baselineSkippedLine(int $count): string
    {
        return sprintf('<fg=gray>%d %s skipped by baseline.</>', $count, Str::plural('file', $count));
    }

    /**
     * Convert the SDK's typed estimates back into the snake_case rows the
     * command output is built from, so the tables and the --json contract
     * stay unchanged.
     *
     * @param  list<SizeEstimate>  $estimates
     * @return list<array<string, mixed>>
     */
    private function estimateRows(array $estimates): array
    {
        return array_map(fn (SizeEstimate $estimate) => [
            'format' => $estimate->format,
            'size' => $estimate->size,
            'saved' => $estimate->saved,
            'saved_percent' => $estimate->savedPercent,
            'quality' => $estimate->quality,
        ], $estimates);
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
     * Count the frames to send with the analysis: only a real animation
     * is worth reporting, a still (or an unknown count) is the API's
     * default. AVIF keeps every frame, so the count is what makes its
     * estimate honest for animated sources.
     */
    private function frames(string $bytes): ?int
    {
        $frames = (new FrameCounter)->count($bytes);

        return $frames !== null && $frames > 1 ? $frames : null;
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
     * Order rows by absolute bytes saved, biggest saver first. Failed
     * rows sink to the bottom.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortBySaved(array $rows): array
    {
        $saved = fn (array $row) => is_int($row['saved'] ?? null) ? $row['saved'] : PHP_INT_MIN;

        usort($rows, fn (array $a, array $b) => $saved($b) <=> $saved($a));

        return $rows;
    }
}
