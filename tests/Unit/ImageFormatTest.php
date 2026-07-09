<?php

use App\Enums\ImageFormat;
use Tests\Fixtures\Images;

test('fromBytes detects each supported format by its magic numbers', function () {
    expect(ImageFormat::fromBytes(Images::jpg()))->toBe(ImageFormat::Jpg)
        ->and(ImageFormat::fromBytes(Images::png()))->toBe(ImageFormat::Png)
        ->and(ImageFormat::fromBytes('GIF89a'.str_repeat("\x00", 20)))->toBe(ImageFormat::Gif)
        ->and(ImageFormat::fromBytes('GIF87a'.str_repeat("\x00", 20)))->toBe(ImageFormat::Gif)
        ->and(ImageFormat::fromBytes('RIFF'."\x24\x00\x00\x00".'WEBPVP8 '))->toBe(ImageFormat::Webp)
        ->and(ImageFormat::fromBytes("\x00\x00\x00\x20ftypavifavifmif1"))->toBe(ImageFormat::Avif);
});

test('fromBytes returns null for unsupported bytes', function () {
    expect(ImageFormat::fromBytes('plain text'))->toBeNull()
        ->and(ImageFormat::fromBytes(''))->toBeNull()
        ->and(ImageFormat::fromBytes('RIFF'."\x24\x00\x00\x00".'WAVEfmt '))->toBeNull()
        ->and(ImageFormat::fromBytes('%PDF-1.7'))->toBeNull();
});
