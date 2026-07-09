<?php

namespace App\Glimpse;

final readonly class ProbeResult
{
    public function __construct(
        public int $width,
        public int $height,
        public float $sampleBpp,
    ) {}
}
