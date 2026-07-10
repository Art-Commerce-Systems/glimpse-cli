<?php

namespace App\Commands\Concerns;

use GlimpseImg\ApiException;
use GlimpseImg\AuthException;
use GlimpseImg\Client;
use GlimpseImg\FrameCounter;
use GlimpseImg\ImageFormat;
use GlimpseImg\SampleProbe;

trait AnalyzesImages
{
    /**
     * Analyze a single file inside a batch. Failures are recorded, not
     * thrown, so one bad file does not abort the scan. Auth failures do
     * abort: they would fail every remaining file the same way.
     *
     * @return array<string, mixed>
     */
    private function analyzeFile(Client $client, SampleProbe $probe, string $dir, string $path, ?ImageFormat $target, ?int $quality): array
    {
        $file = ltrim(substr($path, strlen(rtrim($dir, '/'))), '/');

        try {
            $bytes = $this->readImage($path, limitBytes: false);

            $format = ImageFormat::tryFromBinary($bytes)
                ?? throw new ApiException('Unrecognized image format.');

            [$width, $height, $sampleBpp] = $this->measure($probe, $bytes);

            $estimates = $client->analyze($format, strlen($bytes), $width, $height, $quality, $sampleBpp, $this->frames($bytes));

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
