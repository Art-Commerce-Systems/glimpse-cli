<?php

namespace App\Commands;

use App\Commands\Concerns\GuardsApiErrors;
use App\Glimpse\Config;
use GlimpseImg\AuthException;
use GlimpseImg\Client;
use LaravelZero\Framework\Commands\Command;

class AuthCommand extends Command
{
    use GuardsApiErrors;

    protected $signature = 'auth {--token= : The API token (prompted if omitted)}';

    protected $description = 'Authenticate with the Glimpse API and store the token';

    public function handle(Client $client, Config $config): int
    {
        return $this->runGuarded(function () use ($client, $config) {
            $token = $this->option('token') ?: $this->secret('API token (create one at Settings > API Tokens)');

            if (! is_string($token) || trim($token) === '') {
                $this->error('No token provided.');

                return self::FAILURE;
            }

            $token = trim($token);

            try {
                $user = $client->user($token);
            } catch (AuthException) {
                $this->error('That token was rejected by the API. Nothing was saved.');

                return self::FAILURE;
            }

            $config->setToken($token);

            $this->info(sprintf(
                'Authenticated as %s (%s)',
                $user['name'] ?? 'unknown',
                $user['email'] ?? 'unknown',
            ));
            $this->line('Token saved to '.$config->path());

            return self::SUCCESS;
        });
    }
}
