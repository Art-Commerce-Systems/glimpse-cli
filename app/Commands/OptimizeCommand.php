<?php

namespace MathiasGrimm\GlimpseCli\Commands;

use MathiasGrimm\GlimpseCli\Commands\Concerns\UpdatesBaseline;
use MathiasGrimm\GlimpsePhp\Client;

class OptimizeCommand extends GlimpseCommand
{
    use UpdatesBaseline;

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
            $this->rejectPublicToken();

            $input = $this->inputArgument();
            $output = $this->resolveOutput($input);
            $quality = $this->intOption('quality');

            $result = $client->optimize($this->readImage($input), $quality);

            $path = $this->writeResult($input, $output, 'optimized', $result);
            $this->recordInBaseline($input, $path);
            $this->emit($result, $path);

            return self::SUCCESS;
        });
    }
}
