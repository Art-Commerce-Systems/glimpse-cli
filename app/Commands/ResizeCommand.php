<?php

namespace App\Commands;

use App\Glimpse\ApiException;
use App\Glimpse\Client;

class ResizeCommand extends GlimpseCommand
{
    protected $signature = 'resize
        {input : Path to the image, or - for stdin}
        {--width= : Maximum width in pixels}
        {--height= : Maximum height in pixels}
        {--o|output= : Output path, or - for stdout}
        {--i|in-place : Write the result over the input file}
        {--json : Print the result metadata as JSON}
        {--force : Overwrite the output file if it exists}';

    protected $description = 'Resize an image into a bounding box, keeping its format and aspect ratio';

    public function handle(Client $client): int
    {
        return $this->runGuarded(function () use ($client) {
            $input = $this->inputArgument();
            $output = $this->resolveOutput($input);
            $width = $this->intOption('width');
            $height = $this->intOption('height');

            if ($width === null && $height === null) {
                throw new ApiException('Provide --width and/or --height.');
            }

            $result = $client->resize($this->readImage($input), $width, $height);

            $path = $this->writeResult($input, $output, 'resized', $result);
            $this->emit($result, $path);

            return self::SUCCESS;
        });
    }
}
