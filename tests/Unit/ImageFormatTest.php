<?php

use App\Enums\ImageFormat;
use Tests\Fixtures\Images;

test('tryFromBinary detects each supported format by its magic numbers', function () {
    expect(ImageFormat::tryFromBinary(Images::jpg()))->toBe(ImageFormat::Jpg)
        ->and(ImageFormat::tryFromBinary(Images::png()))->toBe(ImageFormat::Png)
        ->and(ImageFormat::tryFromBinary('GIF89a'.str_repeat("\x00", 20)))->toBe(ImageFormat::Gif)
        ->and(ImageFormat::tryFromBinary('GIF87a'.str_repeat("\x00", 20)))->toBe(ImageFormat::Gif)
        ->and(ImageFormat::tryFromBinary('RIFF'."\x24\x00\x00\x00".'WEBPVP8 '))->toBe(ImageFormat::Webp)
        ->and(ImageFormat::tryFromBinary("\x00\x00\x00\x20ftypavifavifmif1"))->toBe(ImageFormat::Avif);
});

test('tryFromBinary returns null for unsupported bytes', function () {
    expect(ImageFormat::tryFromBinary('plain text'))->toBeNull()
        ->and(ImageFormat::tryFromBinary(''))->toBeNull()
        ->and(ImageFormat::tryFromBinary('RIFF'."\x24\x00\x00\x00".'WAVEfmt '))->toBeNull()
        ->and(ImageFormat::tryFromBinary('%PDF-1.7'))->toBeNull();
});
