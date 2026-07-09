<?php

use App\Glimpse\SampleProbe;
use Tests\Fixtures\Images;

/**
 * Generate a jpeg whose content complexity is controlled: noisy pixels
 * compress poorly, a flat fill compresses extremely well.
 */
function probeImage(bool $noisy, int $width = 96, int $height = 64): string
{
    $image = imagecreatetruecolor($width, $height);

    if ($noisy) {
        mt_srand(42);

        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                imagesetpixel($image, $x, $y, imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
        }
    } else {
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 40, 90, 200));
    }

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

test('reports the original dimensions and a bits-per-pixel measure', function () {
    $result = (new SampleProbe)->measure(probeImage(noisy: true));

    expect($result)->not->toBeNull()
        ->and($result->width)->toBe(96)
        ->and($result->height)->toBe(64)
        ->and($result->sampleBpp)->toBeGreaterThan(0.0);
});

test('noisy content probes a much higher bpp than flat content', function () {
    $noisy = (new SampleProbe)->measure(probeImage(noisy: true));
    $flat = (new SampleProbe)->measure(probeImage(noisy: false));

    expect($noisy->sampleBpp)->toBeGreaterThan($flat->sampleBpp * 3);
});

test('measures the committed fixture images', function () {
    $png = (new SampleProbe)->measure(Images::png());

    expect($png)->not->toBeNull()
        ->and($png->width)->toBe(1)
        ->and($png->height)->toBe(1);
});

test('returns null for bytes no extension can decode', function () {
    expect((new SampleProbe)->measure('definitely not an image'))->toBeNull()
        ->and((new SampleProbe)->measure(''))->toBeNull();
});
