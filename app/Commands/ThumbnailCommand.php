<?php

namespace App\Commands;

use App\Commands\Concerns\UpdatesBaseline;
use GlimpseImg\Client;

class ThumbnailCommand extends GlimpseCommand
{
    use UpdatesBaseline;

    protected $signature = 'thumbnail
        {input : Path to the image, or - for stdin}
        {--width= : Maximum width in pixels (API default 300)}
        {--height= : Maximum height in pixels (API default 300)}
        {--quality= : Re-encode quality 1-100 (API default 60)}
        {--o|output= : Output path, or - for stdout}
        {--i|in-place : Write the result over the input file}
        {--json : Print the result metadata as JSON}
        {--force : Overwrite the output file if it exists}';

    protected $description = 'Create an optimized thumbnail of an image';

    public function handle(Client $client): int
    {
        return $this->runGuarded(function () use ($client) {
            $input = $this->inputArgument();
            $output = $this->resolveOutput($input);

            $result = $client->thumbnail(
                $this->readImage($input),
                $this->intOption('width'),
                $this->intOption('height'),
                $this->intOption('quality'),
            );

            $path = $this->writeResult($input, $output, 'thumb', $result);
            $this->recordInBaseline($input, $path, recordSource: false);
            $this->emit($result, $path);

            return self::SUCCESS;
        });
    }
}
