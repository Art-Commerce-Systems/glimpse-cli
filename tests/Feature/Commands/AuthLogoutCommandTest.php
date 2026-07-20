<?php

use MathiasGrimm\GlimpseCli\Glimpse\Config;

test('removes the stored token', function () {
    $config = app(Config::class);
    $config->setToken('secret');

    $this->artisan('auth:logout')
        ->expectsOutputToContain('Logged out. Token removed from '.$config->path())
        ->assertExitCode(0);

    expect($config->storedToken())->toBeNull();
});

test('reports when there is no stored token', function () {
    $this->artisan('auth:logout')
        ->expectsOutputToContain('No stored token.')
        ->assertExitCode(0);
});
