<?php

namespace App\Glimpse;

use Imagick;
use ImagickException;

/**
 * Counts an image's frames from raw bytes for the analyze endpoint:
 * animated sources change the AVIF estimate, which keeps every frame,
 * and an already-animated AVIF is reported as not worth re-optimizing.
 *
 * GIF and animated AVIF are parsed natively so the count works without
 * any image extension: GIF by walking the block structure (no pixel
 * decoding, sub-blocks are skipped by their length bytes), animated
 * AVIF (the avis image-sequence brand) by summing the sample runs in
 * the container's stts box. ImageMagick 6 cannot even identify avis
 * bytes, so the native parser is the reliable path there too. Anything
 * else falls back to an Imagick header read when the extension is
 * loaded. Null means the frames are unknown; the caller omits the
 * count and the API assumes a still image.
 */
final class FrameCounter
{
    public function count(string $bytes): ?int
    {
        return $this->fromGif($bytes) ?? $this->fromAvifSequence($bytes) ?? $this->withImagick($bytes);
    }

    private function fromGif(string $bytes): ?int
    {
        if (! str_starts_with($bytes, 'GIF87a') && ! str_starts_with($bytes, 'GIF89a')) {
            return null;
        }

        $length = strlen($bytes);

        if ($length < 14) {
            return null;
        }

        $screenFields = ord($bytes[10]);
        $position = 13 + $this->colorTableBytes($screenFields);
        $frames = 0;

        while ($position < $length) {
            $marker = ord($bytes[$position++]);

            if ($marker === 0x3B) { // trailer
                break;
            }

            if ($marker === 0x21) { // extension: label byte, then sub-blocks
                $position = $this->skipSubBlocks($bytes, $position + 1);

                if ($position === null) {
                    return null;
                }

                continue;
            }

            if ($marker !== 0x2C) { // not an image descriptor: corrupt, do not guess
                return null;
            }

            if ($position + 9 > $length) {
                return null;
            }

            $imageFields = ord($bytes[$position + 8]);
            // Descriptor, local color table, LZW minimum code size, then the pixel sub-blocks.
            $position = $this->skipSubBlocks($bytes, $position + 9 + $this->colorTableBytes($imageFields) + 1);

            if ($position === null) {
                return null;
            }

            $frames++;
        }

        return $frames > 0 ? $frames : null;
    }

    /**
     * The size of a GIF color table announced by a packed-fields byte:
     * three bytes per entry, 2^(depth+1) entries, present only when the
     * high bit is set.
     */
    private function colorTableBytes(int $packedFields): int
    {
        return ($packedFields & 0x80) === 0 ? 0 : 3 * (2 ** (($packedFields & 0x07) + 1));
    }

    /**
     * Advance past a GIF sub-block chain: each block starts with its
     * length, a zero length terminates the chain. Null when the data
     * ends mid-chain.
     */
    private function skipSubBlocks(string $bytes, int $position): ?int
    {
        $length = strlen($bytes);

        while ($position < $length) {
            $size = ord($bytes[$position++]);

            if ($size === 0) {
                return $position;
            }

            $position += $size;
        }

        return null;
    }

    private function fromAvifSequence(string $bytes): ?int
    {
        if (substr($bytes, 4, 8) !== 'ftypavis') {
            return null;
        }

        $offset = strpos($bytes, 'stts');

        if ($offset === false || strlen($bytes) < $offset + 12) {
            return null;
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr($bytes, $offset + 8, 4));
        $entryCount = $unpacked[1];

        if (strlen($bytes) < $offset + 12 + $entryCount * 8) {
            return null;
        }

        $frames = 0;

        for ($entry = 0; $entry < $entryCount; $entry++) {
            /** @var array{1: int, 2: int} $pair */
            $pair = unpack('N2', substr($bytes, $offset + 12 + $entry * 8, 8));

            $frames += $pair[1];
        }

        return $frames > 0 ? $frames : null;
    }

    private function withImagick(string $bytes): ?int
    {
        if (! extension_loaded('imagick')) {
            return null;
        }

        $imagick = new Imagick;

        try {
            $imagick->pingImageBlob($bytes);

            return $imagick->getNumberImages();
        } catch (ImagickException) {
            return null;
        } finally {
            $imagick->clear();
        }
    }
}
