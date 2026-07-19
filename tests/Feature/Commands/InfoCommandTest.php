<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * The full /v1/info response body, mirroring the real API shape and enum
 * casing (see ImageInfoResource and the Image* enums on the server).
 *
 * @return array<string, mixed>
 */
function fullInfoApiResponse(): array
{
    return [
        'format' => 'png',
        'mime_type' => 'image/png',
        'width' => 1280,
        'height' => 720,
        'type' => 'TRUECOLOR_ALPHA',
        'colorspace' => 'SRGB',
        'depth' => 8,
        'channel_depths' => ['red' => 8, 'green' => 8, 'blue' => 8, 'alpha' => 8],
        'size' => 48213,
        'resolution' => ['x' => 72.0, 'y' => 72.0],
        'units' => 'PIXELS_PER_INCH',
        'gamma' => 0.4545,
        'interlace' => 'NONE',
        'compression' => 'ZIP',
        'compression_quality' => 92,
        'orientation' => 'TOP_LEFT',
        'rendering_intent' => 'PERCEPTUAL',
        'iterations' => 0,
        'colors' => 187028,
        'chromaticity' => [
            'red' => ['x' => 0.64, 'y' => 0.33],
            'green' => ['x' => 0.3, 'y' => 0.6],
            'blue' => ['x' => 0.15, 'y' => 0.06],
            'white' => ['x' => 0.3127, 'y' => 0.329],
        ],
        'background_color' => 'srgb(255,255,255)',
        'border_color' => 'srgb(223,223,223)',
        'frames' => 1,
        'has_alpha' => true,
        'statistics' => [
            'red' => ['min' => 0.0, 'max' => 1.0, 'mean' => 0.4823, 'standard_deviation' => 0.2511, 'kurtosis' => -1.1204, 'skewness' => 0.1093],
        ],
        'properties' => ['exif:Make' => 'Canon'],
    ];
}

beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');

    Http::fake(['*/v1/info' => Http::response(['data' => fullInfoApiResponse()])]);
});

test('prints a pretty metadata table by default', function () {
    $this->artisan('info', ['input' => createImage()])
        ->expectsOutputToContain('PNG')
        ->expectsOutputToContain('1280 x 720 px')
        ->expectsOutputToContain('72 x 72 PIXELS_PER_INCH')
        ->expectsOutputToContain('ZIP (quality 92)')
        ->expectsOutputToContain('exif:Make: Canon')
        ->assertExitCode(0);
});

test('the --json output mirrors the API response byte for byte', function () {
    $exitCode = Artisan::call('info', ['input' => createImage(), '--json' => true]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toBe(json_encode(fullInfoApiResponse(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
});
