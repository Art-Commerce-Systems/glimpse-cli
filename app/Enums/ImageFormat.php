<?php

namespace App\Enums;

enum ImageFormat: string
{
    case Jpg = 'jpg';
    case Png = 'png';
    case Webp = 'webp';
    case Gif = 'gif';
    case Avif = 'avif';

    public static function fromExtension(string $extension): ?self
    {
        $extension = strtolower($extension);

        return self::tryFrom($extension === 'jpeg' ? 'jpg' : $extension);
    }

    /**
     * Detect the format from raw image bytes by their magic numbers, or
     * null when the bytes are not a supported image. Kept dependency-free
     * on purpose: finfo needs a libmagic recent enough to know AVIF.
     */
    public static function fromBytes(string $bytes): ?self
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => self::Jpg,
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => self::Png,
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => self::Gif,
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => self::Webp,
            substr($bytes, 4, 8) === 'ftypavif' => self::Avif,
            default => null,
        };
    }
}
