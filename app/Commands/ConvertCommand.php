<?php

namespace App\Commands;

use GlimpseImg\ApiException;
use GlimpseImg\Client;
use GlimpseImg\ImageFormat;

class ConvertCommand extends GlimpseCommand
{
    protected $signature = 'convert
        {input : Path to the image, or - for stdin}
        {--format= : Target format (jpg, png, webp, gif, avif); inferred from --output when omitted}
        {--optimize : Run the optimizer chain on the converted image}
        {--quality= : Re-encode quality 1-100; requires --optimize (defaults to 85)}
        {--o|output= : Output path, or - for stdout}
        {--i|in-place : Replace the input file with the converted image, deleting the original}
        {--json : Print the result metadata as JSON}
        {--force : Overwrite the output file if it exists}';

    protected $description = 'Convert an image to another format';

    public function handle(Client $client): int
    {
        return $this->runGuarded(function () use ($client) {
            $input = $this->inputArgument();
            $output = $this->resolveOutput($input);
            $format = $this->resolveFormat($output);
            $optimize = (bool) $this->option('optimize');
            $quality = $this->intOption('quality');

            if ($quality !== null && ! $optimize) {
                throw new ApiException('--quality requires --optimize.');
            }

            if ($this->inPlace()) {
                $target = $this->defaultOutputPath($input, null, $format->value);

                if ($target !== $input && file_exists($target) && ! $this->option('force')) {
                    throw new ApiException("{$target} already exists. Use --force to overwrite.");
                }
            }

            $result = $client->convert($this->readImage($input), $format, $optimize, $quality);

            $path = $this->writeResult($input, $output, null, $result);
            $this->emit($result, $path);

            return self::SUCCESS;
        });
    }

    private function resolveFormat(?string $output): ImageFormat
    {
        $option = $this->option('format');

        if (is_string($option) && $option !== '') {
            return ImageFormat::tryFrom(strtolower($option))
                ?? throw new ApiException("Unsupported format: {$option}. Supported: jpg, png, webp, gif, avif.");
        }

        if ($output !== null && $output !== '-') {
            $format = ImageFormat::fromExtension(pathinfo($output, PATHINFO_EXTENSION));

            if ($format !== null) {
                return $format;
            }
        }

        throw new ApiException('Provide --format, or an output path with a known image extension.');
    }
}
