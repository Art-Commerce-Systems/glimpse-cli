<?php

namespace App\Commands;

use App\Enums\ImageFormat;
use App\Glimpse\ApiException;
use App\Glimpse\Client;

class ConvertCommand extends GlimpseCommand
{
    protected $signature = 'convert
        {input : Path to the image, or - for stdin}
        {--format= : Target format (jpg, png, webp, gif, avif); inferred from --output when omitted}
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

            if ($this->inPlace()) {
                $target = $this->defaultOutputPath($input, null, $format->value);

                if ($target !== $input && file_exists($target) && ! $this->option('force')) {
                    throw new ApiException("{$target} already exists. Use --force to overwrite.");
                }
            }

            $result = $client->convert($this->readImage($input), $format);

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
