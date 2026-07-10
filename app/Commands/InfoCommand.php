<?php

namespace App\Commands;

use GlimpseImg\Client;

class InfoCommand extends GlimpseCommand
{
    protected $signature = 'info
        {input : Path to the image, or - for stdin}
        {--json : Print the raw metadata as JSON}';

    protected $description = 'Inspect an image and print its metadata';

    public function handle(Client $client): int
    {
        return $this->runGuarded(function () use ($client) {
            $info = $client->info($this->readImage($this->inputArgument()));

            if ($this->option('json')) {
                $this->line((string) json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $this->render($info);

            return self::SUCCESS;
        });
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function render(array $info): void
    {
        $rows = [];

        $add = function (string $label, mixed $value) use (&$rows): void {
            if ($value !== null && $value !== '') {
                $rows[] = [$label, is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value];
            }
        };

        $width = data_get($info, 'width');
        $height = data_get($info, 'height');
        $size = data_get($info, 'size');
        $resolutionX = data_get($info, 'resolution.x');
        $resolutionY = data_get($info, 'resolution.y');
        $compression = data_get($info, 'compression');
        $quality = data_get($info, 'quality');

        $add('Format', is_string(data_get($info, 'format')) ? strtoupper((string) data_get($info, 'format')) : null);
        $add('Mime type', data_get($info, 'mime_type'));
        $add('Dimensions', $width !== null && $height !== null ? "{$width} x {$height} px" : null);
        $add('Size', is_int($size) ? $this->humanSize($size) : null);
        $add('Type', data_get($info, 'type'));
        $add('Colorspace', data_get($info, 'colorspace'));
        $add('Depth', data_get($info, 'depth'));
        $add('Resolution', $resolutionX !== null && $resolutionY !== null
            ? trim("{$resolutionX} x {$resolutionY} ".(string) data_get($info, 'units', ''))
            : null);
        $add('Compression', $compression !== null && $quality !== null ? "{$compression} (quality {$quality})" : $compression);
        $add('Orientation', data_get($info, 'orientation'));
        $add('Frames', data_get($info, 'frames'));
        $add('Colors', data_get($info, 'colors'));
        $add('Alpha', data_get($info, 'has_alpha'));

        $this->table(['Property', 'Value'], $rows);

        $properties = data_get($info, 'properties');

        if (is_array($properties) && $properties !== []) {
            $this->newLine();
            $this->line('<options=bold>Properties</>');

            foreach ($properties as $key => $value) {
                $value = is_scalar($value) ? (string) $value : (string) json_encode($value);
                $this->line("  {$key}: {$value}");
            }
        }
    }
}
