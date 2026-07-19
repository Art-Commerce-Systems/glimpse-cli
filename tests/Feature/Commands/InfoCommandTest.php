<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');

    Http::fake(['*/v1/info' => Http::response(['data' => [
        'format' => 'png',
        'mime_type' => 'image/png',
        'width' => 1280,
        'height' => 720,
        'type' => 'TrueColorAlpha',
        'colorspace' => 'sRGB',
        'depth' => 8,
        'size' => 48213,
        'resolution' => ['x' => 72, 'y' => 72],
        'units' => 'PixelsPerInch',
        'compression' => 'Zip',
        'compression_quality' => 92,
        'orientation' => 'TopLeft',
        'frames' => 1,
        'has_alpha' => true,
        'properties' => ['exif:Make' => 'Canon'],
    ]])]);
});

test('prints a pretty metadata table by default', function () {
    $this->artisan('info', ['input' => createImage()])
        ->expectsOutputToContain('PNG')
        ->expectsOutputToContain('1280 x 720 px')
        ->expectsOutputToContain('Zip (quality 92)')
        ->expectsOutputToContain('exif:Make: Canon')
        ->assertExitCode(0);
});

test('prints the raw metadata with --json', function () {
    $exitCode = Artisan::call('info', ['input' => createImage(), '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($decoded['format'])->toBe('png')
        ->and($decoded['has_alpha'])->toBeTrue()
        ->and($decoded['properties'])->toBe(['exif:Make' => 'Canon']);
});
