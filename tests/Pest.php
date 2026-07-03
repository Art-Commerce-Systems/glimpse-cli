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
 * Write a PNG fixture into the test workspace and return its path.
 */
function createImage(string $name = 'photo.png'): string
{
    $dir = test()->configHome.'/workspace';

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $path = $dir.'/'.$name;
    file_put_contents($path, Images::png());

    return $path;
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
