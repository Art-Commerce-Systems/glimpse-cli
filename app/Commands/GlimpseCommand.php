<?php

namespace App\Commands;

use App\Commands\Concerns\GuardsApiErrors;
use GlimpseImg\ApiException;
use GlimpseImg\ImageResult;
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

    /**
     * Read the input image bytes. The size limit mirrors the API's upload
     * cap; commands that never upload the bytes (analyze) disable it.
     */
    protected function readImage(string $path, bool $limitBytes = true): string
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

        if ($limitBytes && strlen($bytes) > self::MAX_INPUT_BYTES) {
            throw new ApiException('The image exceeds the 15 MiB limit.');
        }

        return $bytes;
    }

    /**
     * Resolve the final output path, write the result to it, and remove the
     * original when an in-place write changed the extension.
     */
    protected function writeResult(string $input, ?string $output, ?string $suffix, ImageResult $result): string
    {
        $path = $output ?? $this->defaultOutputPath($input, $this->inPlace() ? null : $suffix, $result->format);

        if ($path === '-') {
            fwrite(STDOUT, $result->bytes);

            return $path;
        }

        $overwritingInput = $this->inPlace() && $path === $input;

        if (file_exists($path) && ! $overwritingInput && ! $this->option('force')) {
            throw new ApiException("{$path} already exists. Use --force to overwrite.");
        }

        file_put_contents($path, $result->bytes);

        if ($this->inPlace() && $path !== $input) {
            unlink($input);
        }

        return $path;
    }

    /**
     * Resolve the --output option, failing fast on unusable combinations
     * before any bytes are uploaded.
     */
    protected function resolveOutput(string $input): ?string
    {
        $output = $this->option('output');
        $output = is_string($output) && $output !== '' ? $output : null;

        if ($this->inPlace()) {
            if ($output !== null) {
                throw new ApiException('--in-place cannot be combined with -o/--output.');
            }

            if ($input === '-') {
                throw new ApiException('--in-place cannot be used when reading from stdin.');
            }
        }

        if ($input === '-' && $output === null) {
            throw new ApiException('Provide -o/--output when reading from stdin.');
        }

        if ($output !== null && $output !== '-' && file_exists($output) && ! $this->option('force')) {
            throw new ApiException("{$output} already exists. Use --force to overwrite.");
        }

        return $output;
    }

    protected function inPlace(): bool
    {
        return (bool) $this->option('in-place');
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
