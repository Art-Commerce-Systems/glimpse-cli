<?php

use App\Glimpse\Config;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

test('reports when not authenticated', function () {
    Http::fake();

    $this->artisan('auth:status')
        ->expectsOutputToContain('Not authenticated. Run: glimpse auth')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('shows the API url, a masked token, and the identity', function () {
    app(Config::class)->setToken('secret-token-1234');

    Http::fake(['*/user' => Http::response(['id' => 7, 'name' => 'Mathias', 'email' => 'mathias@example.com', 'created_at' => '2025-11-03T09:30:00.000000Z'])]);

    $this->artisan('auth:status')
        ->expectsOutputToContain('API URL: https://glimpseimg.com/api')
        ->expectsOutputToContain('Token:   secr...1234')
        ->expectsOutputToContain('Authenticated as Mathias (mathias@example.com)')
        ->assertExitCode(0);
});

test('an invalid stored token surfaces the auth hint', function () {
    app(Config::class)->setToken('stale-token-9999');

    Http::fake(['*/user' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $this->artisan('auth:status')
        ->expectsOutputToContain('Invalid or missing token. Run: glimpse auth')
        ->assertExitCode(1);
});

test('reports the built-in public token as not authenticated, without calling the API', function () {
    putenv('GLIMPSE_TOKEN');
    app()->instance(Config::class, new Config(publicTokenOverride: 'pub-token'));
    Http::fake();

    $exitCode = Artisan::call('auth:status');

    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('built-in public CI token')
        ->and($output)->toContain('The public token only runs check and analyze.');

    Http::assertNothingSent();
});
