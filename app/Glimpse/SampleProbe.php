<?php

namespace App\Glimpse;

use Imagick;
use ImagickException;

/**
 * Measures an image's content complexity for the estimate endpoint by
 * performing a real JPEG trial encode locally, matching the API's probe
 * spec: downscale to at most 4096 px on the longest side (memory guard;
 * a mild downscale barely disturbs the signal), encode JPEG at quality
 * 85, and report bits per pixel of the result. The reported width and
 * height are always the ORIGINAL dimensions.
 *
 * Prefers Imagick (decodes AVIF and CMYK), falls back to GD, and returns
 * null when neither extension can decode the bytes; the caller then
 * degrades to metadata-only estimates.
 */
final class SampleProbe
{
    private const MAX_SAMPLE_EDGE = 4096;

    private const JPEG_QUALITY = 85;

    public function measure(string $bytes): ?ProbeResult
    {
        return $this->withImagick($bytes) ?? $this->withGd($bytes);
    }

    private function withImagick(string $bytes): ?ProbeResult
    {
        if (! extension_loaded('imagick')) {
            return null;
        }

        $imagick = new Imagick;

        try {
            $imagick->readImageBlob($bytes);
            $imagick->setFirstIterator();

            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();

            if (max($width, $height) > self::MAX_SAMPLE_EDGE) {
                $imagick->thumbnailImage(
                    $width >= $height ? self::MAX_SAMPLE_EDGE : 0,
                    $width >= $height ? 0 : self::MAX_SAMPLE_EDGE,
                );
            }

            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(self::JPEG_QUALITY);

            $sample = $imagick->getImageBlob();
            $sampleWidth = $imagick->getImageWidth();
            $sampleHeight = $imagick->getImageHeight();
        } catch (ImagickException) {
            return null;
        } finally {
            $imagick->clear();
        }

        return new ProbeResult($width, $height, $this->bitsPerPixel($sample, $sampleWidth, $sampleHeight));
    }

    private function withGd(string $bytes): ?ProbeResult
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $sampleWidth = $width;
        $sampleHeight = $height;
        $longest = max($width, $height);

        if ($longest > self::MAX_SAMPLE_EDGE) {
            $scaled = imagescale($image, (int) round($width * self::MAX_SAMPLE_EDGE / $longest), (int) round($height * self::MAX_SAMPLE_EDGE / $longest));

            if ($scaled !== false) {
                $image = $scaled;
                $sampleWidth = imagesx($image);
                $sampleHeight = imagesy($image);
            }
        }

        ob_start();
        imagejpeg($image, null, self::JPEG_QUALITY);
        $sample = (string) ob_get_clean();

        return new ProbeResult($width, $height, $this->bitsPerPixel($sample, $sampleWidth, $sampleHeight));
    }

    private function bitsPerPixel(string $sample, int $width, int $height): float
    {
        return round(strlen($sample) * 8 / ($width * $height), 4);
    }
}
