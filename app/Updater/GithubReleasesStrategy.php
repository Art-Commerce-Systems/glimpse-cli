<?php

namespace MathiasGrimm\GlimpseCli\Updater;

use Humbug\SelfUpdate\Strategy\GithubStrategy as HumbugGithubStrategy;
use Humbug\SelfUpdate\Updater;
use LaravelZero\Framework\Components\Updater\Strategy\StrategyInterface;
use Phar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Downloads new versions from the PHAR asset attached to the GitHub release
 * of the matching Packagist tag. Laravel Zero ships a GithubReleasesStrategy,
 * but nothing in the framework ever sets its phar name, so the asset URL it
 * builds ends in a bare slash and 404s; this subclass (the framework's class
 * is final) derives the asset name from the running PHAR instead. Releases
 * must attach the binary under its distributed name: glimpse.
 */
class GithubReleasesStrategy extends HumbugGithubStrategy implements StrategyInterface
{
    /**
     * @return string
     */
    public function getPharName()
    {
        return parent::getPharName() ?: basename(Phar::running());
    }

    /** {@inheritdoc} */
    public function download(Updater $updater)
    {
        // Right after download() the updater swaps the running PHAR for the
        // new one, and from then on nothing can be autoloaded from it: the
        // phar stream cache still holds the old manifest, so includes fail
        // with zlib data errors. Rendering a success block into the void
        // preloads every console class the real post-update message needs.
        (new SymfonyStyle(new ArrayInput([]), new NullOutput))->success('warm-up');

        parent::download($updater);
    }
}
