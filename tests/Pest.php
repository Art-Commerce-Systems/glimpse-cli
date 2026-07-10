<?php

use Illuminate\Support\Facades\File;
use Tests\Fixtures\Images;
use Tests\TestCase;

uses(TestCase::class)
    ->beforeEach(function () {
        $this->configHome = sys_get_temp_dir().'/glimpse-cli-test-'.bin2hex(random_bytes(6));

        putenv('XDG_CONFIG_HOME='.$this->configHome);
        putenv('GLIMPSE_TOKEN');
        putenv('GLIMPSE_API_URL');
    })
    ->afterEach(function () {
        if ($this->configHome !== '' && is_dir($this->configHome)) {
            File::deleteDirectory($this->configHome);
        }

        putenv('XDG_CONFIG_HOME');
        putenv('GLIMPSE_TOKEN');
        putenv('GLIMPSE_API_URL');
    })
    ->in('Feature', 'Unit');

/**
 * The directory createImage() writes fixtures into.
 */
function workspace(): string
{
    return test()->configHome.'/workspace';
}

/**
 * Write an image fixture into the test workspace and return its path.
 * Nested names create the intermediate directories; custom contents
 * allow planting corrupt or non-PNG files.
 */
function createImage(string $name = 'photo.png', ?string $contents = null): string
{
    $path = workspace().'/'.$name;

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, $contents ?? Images::png());

    return $path;
}

/**
 * A canned successful analyze-endpoint response envelope.
 *
 * @return array{data: list<array<string, mixed>>}
 */
function fakeAnalyzeResponse(): array
{
    return ['data' => [
        ['format' => 'jpg', 'size' => 812000, 'saved' => 1688000, 'saved_percent' => 67.5, 'quality' => 85],
        ['format' => 'png', 'size' => 6100000, 'saved' => -3600000, 'saved_percent' => -144.0, 'quality' => null],
        ['format' => 'webp', 'size' => 590000, 'saved' => 1910000, 'saved_percent' => 76.4, 'quality' => 85],
        ['format' => 'avif', 'size' => 470000, 'saved' => 2030000, 'saved_percent' => 81.2, 'quality' => 85],
    ]];
}

/**
 * A canned successful transform-endpoint response envelope.
 *
 * @return array{data: array<string, mixed>}
 */
function fakeTransformResponse(string $format = 'jpg', string $mimeType = 'image/jpeg'): array
{
    return ['data' => [
        'output' => ['type' => 'BASE64', 'data' => Images::JPG_BASE64],
        'format' => $format,
        'mime_type' => $mimeType,
        'size' => strlen(Images::jpg()),
        'width' => 1280,
        'height' => 720,
    ]];
}
