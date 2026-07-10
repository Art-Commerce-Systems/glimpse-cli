<?php

use App\Glimpse\FrameCounter;
use Tests\Fixtures\Images;

test('counts the frames of an animated gif without any image extension', function () {
    expect((new FrameCounter)->count(Images::animatedGif()))->toBe(3);
});

test('counts a still gif as one frame', function () {
    $image = imagecreatetruecolor(4, 4);

    ob_start();
    imagegif($image);
    $still = (string) ob_get_clean();

    expect((new FrameCounter)->count($still))->toBe(1);
});

test('sums the stts sample runs of an avif image sequence', function () {
    // A synthetic avis container: ImageMagick cannot identify these bytes
    // (that is exactly why the fallback exists), so the count must come
    // from the stts box: two runs of 12 and 8 samples means 20 frames.
    $stts = pack('N', 28).'stts'.pack('N', 0).pack('N', 2).pack('N2', 12, 4).pack('N2', 8, 4);
    $bytes = pack('N', 16).'ftypavisavif'.$stts;

    expect((new FrameCounter)->count($bytes))->toBe(20);
});

test('returns null for an avif sequence without a readable stts box', function () {
    expect((new FrameCounter)->count(pack('N', 16).'ftypavisavif'))->toBeNull();
});

test('returns null for unrecognizable bytes', function () {
    expect((new FrameCounter)->count('definitely not an image'))->toBeNull()
        ->and((new FrameCounter)->count(''))->toBeNull();
});

test('returns null for a truncated gif instead of guessing', function () {
    expect((new FrameCounter)->count(substr(Images::animatedGif(), 0, 40)))->toBeNull();
});
