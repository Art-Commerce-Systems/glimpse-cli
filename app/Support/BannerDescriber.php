<?php

namespace MathiasGrimm\GlimpseCli\Support;

use Illuminate\Console\Application;
use NunoMaduro\LaravelConsoleSummary\Contracts\DescriberContract;
use NunoMaduro\LaravelConsoleSummary\Describer;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The vendor summary describer minus the "Glimpse vX" title line, which the
 * banner already covers. Deliberately not bound to DescriberContract so
 * `glimpse list` keeps the stock vendor output.
 */
final class BannerDescriber extends Describer
{
    protected function describeTitle(Application $application, OutputInterface $output): DescriberContract
    {
        return $this;
    }
}
