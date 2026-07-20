<?php

namespace MathiasGrimm\GlimpseCli\Commands;

use LaravelZero\Framework\Commands\Command;
use MathiasGrimm\GlimpseCli\Glimpse\Config;

class AuthLogoutCommand extends Command
{
    protected $signature = 'auth:logout';

    protected $description = 'Remove the stored API token';

    public function handle(Config $config): int
    {
        if ($config->storedToken() === null) {
            $this->info('No stored token.');

            return self::SUCCESS;
        }

        $config->setToken(null);

        $this->info('Logged out. Token removed from '.$config->path());

        return self::SUCCESS;
    }
}
