<?php

namespace App\Commands;

use App\Enums\ImageFormat;
use App\Glimpse\ApiException;
use App\Glimpse\Client;

class EstimateCommand extends GlimpseCommand
{
    protected $signature = 'estimate
        {input : Path to the image, or - for stdin}
        {--quality= : Assumed re-encode quality 1-100, perceptual scale (defaults to 85)}
        {--json : Print the estimates as JSON}';

    protected $description = 'Estimate converted sizes for every format without uploading the image';

    public function handle(Client $client): int
    {
        return $this->runGuarded(function () use ($client) {
            $bytes = $this->readImage($this->inputArgument(), limitBytes: false);

            $format = ImageFormat::tryFromBinary($bytes)
                ?? throw new ApiException('Unrecognized image format. Supported: jpg, png, webp, gif, avif.');

            [$width, $height] = $this->dimensions($bytes);

            $estimates = $client->estimate($format, strlen($bytes), $width, $height, $this->intOption('quality'));

            if ($this->option('json')) {
                $this->line((string) json_encode($estimates, JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $this->render($format, strlen($bytes), $width, $height, $estimates);

            return self::SUCCESS;
        });
    }

    /**
     * Read the pixel dimensions locally so the API can give pixel-based
     * estimates. Returns nulls when PHP cannot parse the format (AVIF),
     * which degrades the estimates to size-ratio heuristics.
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
     * @param  list<array<string, mixed>>  $estimates
     */
    private function render(ImageFormat $format, int $size, ?int $width, ?int $height, array $estimates): void
    {
        $source = strtoupper($format->value).', '.$this->humanSize($size);

        if ($width !== null && $height !== null) {
            $source .= ", {$width}x{$height}";
        }

        $this->line("<options=bold>Source</>: {$source}");
        $this->newLine();

        $rows = array_map(function (array $estimate) {
            $estimatedSize = data_get($estimate, 'size');
            $saved = data_get($estimate, 'saved');
            $savedPercent = data_get($estimate, 'saved_percent');
            $quality = data_get($estimate, 'quality');

            return [
                is_string(data_get($estimate, 'format')) ? strtoupper((string) data_get($estimate, 'format')) : '?',
                is_int($estimatedSize) ? '~'.$this->humanSize($estimatedSize) : '?',
                is_int($saved) ? ($saved < 0 ? '-' : '').$this->humanSize(abs($saved)) : '?',
                is_numeric($savedPercent) ? $savedPercent.'%' : '?',
                $quality === null ? '-' : (string) $quality,
            ];
        }, $estimates);

        $this->table(['Format', 'Estimated size', 'Saved', 'Saved %', 'Quality'], $rows);

        $this->line('<fg=gray>Estimates are heuristics for picking a target format, not guarantees.</>');
    }
}
