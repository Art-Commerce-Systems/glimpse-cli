<?php

namespace App\Commands;

use App\Commands\Concerns\GuardsApiErrors;
use App\Glimpse\ApiException;
use App\Glimpse\ImageResult;
use LaravelZero\Framework\Commands\Command;

abstract class GlimpseCommand extends Command
{
    use GuardsApiErrors;

    protected const MAX_INPUT_BYTES = 15 * 1024 * 1024;

    protected function inputArgument(): string
    {
        $input = $this->argument('input');

        return is_string($input) ? $input : '';
    }

    protected function readImage(string $path): string
    {
        if ($path === '-') {
            $bytes = (string) stream_get_contents(STDIN);
        } else {
            if (! is_file($path)) {
                throw new ApiException("File not found: {$path}");
            }

            $bytes = (string) file_get_contents($path);
        }

        if ($bytes === '') {
            throw new ApiException('The input image is empty.');
        }

        if (strlen($bytes) > self::MAX_INPUT_BYTES) {
            throw new ApiException('The image exceeds the 15 MiB limit.');
        }

        return $bytes;
    }

    protected function writeImage(string $path, string $bytes): void
    {
        if ($path === '-') {
            fwrite(STDOUT, $bytes);

            return;
        }

        if (file_exists($path) && ! $this->option('force')) {
            throw new ApiException("{$path} already exists. Use --force to overwrite.");
        }

        file_put_contents($path, $bytes);
    }

    /**
     * Resolve the --output option, failing fast on unusable combinations
     * before any bytes are uploaded.
     */
    protected function resolveOutput(string $input): ?string
    {
        $output = $this->option('output');
        $output = is_string($output) && $output !== '' ? $output : null;

        if ($input === '-' && $output === null) {
            throw new ApiException('Provide -o/--output when reading from stdin.');
        }

        if ($output !== null && $output !== '-' && file_exists($output) && ! $this->option('force')) {
            throw new ApiException("{$output} already exists. Use --force to overwrite.");
        }

        return $output;
    }

    protected function defaultOutputPath(string $input, ?string $suffix, string $extension): string
    {
        $dir = dirname($input);
        $name = pathinfo($input, PATHINFO_FILENAME);

        $filename = implode('.', array_filter([$name, $suffix, $extension], fn ($part) => $part !== null && $part !== ''));

        return ($dir === '.' ? '' : $dir.'/').$filename;
    }

    protected function intOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new ApiException("The --{$name} option must be a number.");
        }

        return (int) $value;
    }

    protected function emit(ImageResult $result, string $path): void
    {
        if ($this->option('json')) {
            $line = (string) json_encode([
                'output' => $path,
                'format' => $result->format,
                'mime_type' => $result->mimeType,
                'size' => $result->size,
                'width' => $result->width,
                'height' => $result->height,
            ], JSON_UNESCAPED_SLASHES);

            $path === '-' ? fwrite(STDERR, $line.PHP_EOL) : $this->line($line);

            return;
        }

        $summary = sprintf(
            'Wrote %s (%s, %s, %dx%d)',
            $path === '-' ? 'stdout' : $path,
            $result->mimeType,
            $this->humanSize($result->size),
            $result->width,
            $result->height,
        );

        $path === '-' ? fwrite(STDERR, $summary.PHP_EOL) : $this->info($summary);
    }

    protected function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
