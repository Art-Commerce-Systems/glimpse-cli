<?php

namespace MathiasGrimm\GlimpseCli\Commands;

use MathiasGrimm\GlimpseCli\Commands\Concerns\UpdatesBaseline;
use MathiasGrimm\GlimpsePhp\ApiException;
use MathiasGrimm\GlimpsePhp\Client;

class ResizeCommand extends GlimpseCommand
{
    use UpdatesBaseline;

    protected $signature = 'resize
        {input : Path to the image, or - for stdin}
        {--width= : Maximum width in pixels}
        {--height= : Maximum height in pixels}
        {--optimize : Run the optimizer chain on the resized image}
        {--quality= : Re-encode quality 1-100; requires --optimize (defaults to 85)}
        {--o|output= : Output path, or - for stdout}
        {--i|in-place : Write the result over the input file}
        {--json : Print the result metadata as JSON}
        {--force : Overwrite the output file if it exists}';

    protected $description = 'Resize an image into a bounding box, keeping its format and aspect ratio';

    public function handle(Client $client): int
    {
        return $this->runGuarded(function () use ($client) {
            $this->rejectPublicToken();

            $input = $this->inputArgument();
            $output = $this->resolveOutput($input);
            $width = $this->intOption('width');
            $height = $this->intOption('height');

            if ($width === null && $height === null) {
                throw new ApiException('Provide --width and/or --height.');
            }

            $optimize = (bool) $this->option('optimize');
            $quality = $this->intOption('quality');

            if ($quality !== null && ! $optimize) {
                throw new ApiException('--quality requires --optimize.');
            }

            $result = $client->resize($this->readImage($input), $width, $height, $optimize, $quality);

            $path = $this->writeResult($input, $output, 'resized', $result);
            $this->recordInBaseline($input, $path, recordSource: false);
            $this->emit($result, $path);

            return self::SUCCESS;
        });
    }
}
