<?php

namespace MathiasGrimm\GlimpseCli\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use MathiasGrimm\GlimpseCli\Glimpse\Config;
use MathiasGrimm\GlimpsePhp\Client;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Config::class);

        // The Http facade resolves Factory::class, so binding it as a
        // singleton lets Http::fake() intercept the SDK client's requests.
        $this->app->singleton(Factory::class);

        $this->app->singleton(Client::class, function (Application $app) {
            $config = $app->make(Config::class);

            return new Client($app->make(Factory::class), fn (): ?string => $config->token(), $config->apiUrl());
        });
    }
}
