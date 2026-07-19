<?php

namespace App\Commands;

use App\Commands\Concerns\GuardsApiErrors;
use App\Glimpse\Config;
use GlimpseImg\Client;
use LaravelZero\Framework\Commands\Command;

class AuthStatusCommand extends Command
{
    use GuardsApiErrors;

    protected $signature = 'auth:status';

    protected $description = 'Show the current authentication status';

    public function handle(Client $client, Config $config): int
    {
        return $this->runGuarded(function () use ($client, $config) {
            $this->line('API URL: '.$config->apiUrl());

            $token = $config->token();

            if ($token === null) {
                $this->line('Token:   (none)');
                $this->error('Not authenticated. Run: glimpse auth');

                return self::FAILURE;
            }

            $this->line('Token:   '.$this->mask($token));

            $user = $client->user();

            $this->info(sprintf('Authenticated as %s (%s)', $user->name, $user->email));

            return self::SUCCESS;
        });
    }

    private function mask(string $token): string
    {
        return strlen($token) <= 8
            ? '****'
            : substr($token, 0, 4).'...'.substr($token, -4);
    }
}
