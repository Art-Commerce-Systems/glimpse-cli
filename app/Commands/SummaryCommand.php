<?php

namespace MathiasGrimm\GlimpseCli\Commands;

use Illuminate\Console\Application;
use Illuminate\Contracts\Container\Container;
use MathiasGrimm\GlimpseCli\Support\Banner;
use MathiasGrimm\GlimpseCli\Support\BannerDescriber;
use NunoMaduro\LaravelConsoleSummary\SummaryCommand as BaseSummaryCommand;
use Symfony\Component\Console\Command\ListCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The default command for the bare `glimpse` invocation: the brand banner
 * followed by the standard summary. Subclassing the vendor SummaryCommand
 * keeps the ListCommand definition (--format, --raw, --help) intact, and
 * renaming it to `summary` leaves the vendor `list` command untouched.
 */
final class SummaryCommand extends BaseSummaryCommand
{
    public function __construct(Container $container)
    {
        parent::__construct($container);

        $this->setName('summary');
        $this->setHidden(true);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $application = $this->getApplication();

        if ($input->getOption('format') === 'txt' && ! $input->getOption('raw') && $application instanceof Application) {
            (new Banner)->render($application->getVersion(), $output);

            $this->container->make(BannerDescriber::class)->describe($application, $output);

            return 0;
        }

        // Not parent::execute(): the vendor guard reads static::FORMAT, a
        // private const that late static binding fails to resolve from a
        // subclass. Delegate to ListCommand directly, as the vendor does.
        return ListCommand::execute($input, $output);
    }
}
