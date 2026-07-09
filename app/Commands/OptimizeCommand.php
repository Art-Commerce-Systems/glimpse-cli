<?php

namespace App\Commands;

use App\Glimpse\Client;

class OptimizeCommand extends GlimpseCommand
{
    protected $signature = 'optimize
        {input : Path to the image, or - for stdin}
        {--quality= : Re-encode quality 1-100 (omit for the lossless optimizer chain)}
        {--o|output= : Output path, or - for stdout}
        {--i|in-place : Write the result over the input file}
        {--json : Print the result metadata as JSON}
        {--force : Overwrite the output file if it exists}';

    protected $description = 'Optimize an image, keeping its format';

    public function handle(Client $client): int
    {
        return $this->runGuarded(function () use ($client) {
            $input = $this->inputArgument();
            $output = $this->resolveOutput($input);
            $quality = $this->intOption('quality');

            $result = $client->optimize($this->readImage($input), $quality);

            $path = $this->writeResult($input, $output, 'optimized', $result);
            $this->emit($result, $path);

            return self::SUCCESS;
        });
    }
}
