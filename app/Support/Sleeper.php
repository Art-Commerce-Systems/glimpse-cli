<?php

namespace MathiasGrimm\GlimpseCli\Support;

/**
 * Wraps sleep() so tests can record the requested delays instead of
 * actually waiting them out.
 */
class Sleeper
{
    public function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
