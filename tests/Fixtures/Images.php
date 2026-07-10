<?php

namespace Tests\Fixtures;

final class Images
{
    /**
     * A 1x1 PNG, base64-encoded.
     */
    public const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /**
     * A tiny JPEG-like payload, base64-encoded. The API is faked in tests,
     * so the bytes only need to be stable, not a decodable image.
     */
    public const JPG_BASE64 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';

    /**
     * A 4x4 three-frame animated GIF (red, lime, blue at 10cs each),
     * base64-encoded.
     */
    public const ANIMATED_GIF_BASE64 = 'R0lGODlhBAAEAPAAAP8AAAAAACH/C05FVFNDQVBFMi4wAwEAAAAh+QQACgAAACwAAAAABAAEAAACBISPCQUAIfkEAAoAAAAsAAAAAAQABACAAP8AAAAAAgSEjwkFACH5BAAKAAAALAAAAAAEAAQAgAAA/wAAAAIEhI8JBQA7';

    public static function png(): string
    {
        return (string) base64_decode(self::PNG_BASE64, true);
    }

    public static function animatedGif(): string
    {
        return (string) base64_decode(self::ANIMATED_GIF_BASE64, true);
    }

    public static function jpg(): string
    {
        return (string) base64_decode(self::JPG_BASE64, true);
    }
}
