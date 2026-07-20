<?php

namespace App\Commands;

use GlimpseImg\Client;
use GlimpseImg\ImageInfo;

class InfoCommand extends GlimpseCommand
{
    protected $signature = 'info
        {input : Path to the image, or - for stdin}
        {--json : Print the raw metadata as JSON}';

    protected $description = 'Inspect an image and print its metadata';

    public function handle(Client $client): int
    {
        return $this->runGuarded(function () use ($client) {
            $this->rejectPublicToken();

            $info = $client->info($this->readImage($this->inputArgument()));

            if ($this->option('json')) {
                $this->line((string) json_encode($this->toArray($info), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $this->render($info);

            return self::SUCCESS;
        });
    }

    /**
     * The snake_case metadata for --json, mirroring the API response
     * shape so the output contract survives the SDK's typed results.
     *
     * @return array<string, mixed>
     */
    private function toArray(ImageInfo $info): array
    {
        return [
            'format' => $info->format,
            'mime_type' => $info->mimeType,
            'width' => $info->width,
            'height' => $info->height,
            'type' => $info->type,
            'colorspace' => $info->colorspace,
            'depth' => $info->depth,
            'channel_depths' => $info->channelDepths,
            'size' => $info->size,
            'resolution' => ['x' => $info->resolution->x, 'y' => $info->resolution->y],
            'units' => $info->units,
            'gamma' => $info->gamma,
            'interlace' => $info->interlace,
            'compression' => $info->compression,
            'compression_quality' => $info->compressionQuality,
            'orientation' => $info->orientation,
            'rendering_intent' => $info->renderingIntent,
            'iterations' => $info->iterations,
            'colors' => $info->colors,
            'chromaticity' => $info->chromaticity,
            'background_color' => $info->backgroundColor,
            'border_color' => $info->borderColor,
            'frames' => $info->frames,
            'has_alpha' => $info->hasAlpha,
            'statistics' => $info->statistics,
            'properties' => $info->properties,
        ];
    }

    private function render(ImageInfo $info): void
    {
        $rows = [];

        $add = function (string $label, mixed $value) use (&$rows): void {
            if ($value !== null && $value !== '') {
                $rows[] = [$label, is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value];
            }
        };

        $add('Format', strtoupper($info->format));
        $add('Mime type', $info->mimeType);
        $add('Dimensions', "{$info->width} x {$info->height} px");
        $add('Size', $this->humanSize($info->size));
        $add('Type', $info->type);
        $add('Colorspace', $info->colorspace);
        $add('Depth', $info->depth);
        $add('Resolution', $info->resolution->x > 0 && $info->resolution->y > 0
            ? trim("{$info->resolution->x} x {$info->resolution->y} ".($info->units ?? ''))
            : null);
        $add('Compression', $info->compression !== null && $info->compressionQuality > 0
            ? "{$info->compression} (quality {$info->compressionQuality})"
            : $info->compression);
        $add('Orientation', $info->orientation);
        $add('Frames', $info->frames);
        $add('Colors', $info->colors);
        $add('Alpha', $info->hasAlpha);

        $this->table(['Property', 'Value'], $rows);

        if ($info->properties !== []) {
            $this->newLine();
            $this->line('<options=bold>Properties</>');

            foreach ($info->properties as $key => $value) {
                $this->line("  {$key}: {$value}");
            }
        }
    }
}
