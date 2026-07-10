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
     * Detect the format from raw image bytes, or null when the bytes are
     * not a supported image. Mirrors the API's ImageFormat::tryFromBinary
     * in name and contract, but sniffs magic numbers instead of finfo on
     * purpose: the CLI runs on arbitrary machines, and finfo needs a
     * libmagic recent enough to know AVIF.
     */
    public static function tryFromBinary(string $binary): ?self
    {
        return match (true) {
            str_starts_with($binary, "\xFF\xD8\xFF") => self::Jpg,
            str_starts_with($binary, "\x89PNG\r\n\x1A\n") => self::Png,
            str_starts_with($binary, 'GIF87a'), str_starts_with($binary, 'GIF89a') => self::Gif,
            str_starts_with($binary, 'RIFF') && substr($binary, 8, 4) === 'WEBP' => self::Webp,
            substr($binary, 4, 8) === 'ftypavif', substr($binary, 4, 8) === 'ftypavis' => self::Avif,
            default => null,
        };
    }
}
