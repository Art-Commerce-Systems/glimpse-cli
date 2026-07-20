<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MathiasGrimm\GlimpseCli\Glimpse\Config;

test('verifies the token against /user and saves it', function () {
    Http::fake(['*/user' => Http::response(['id' => 7, 'name' => 'Mathias', 'email' => 'mathias@example.com', 'created_at' => '2025-11-03T09:30:00.000000Z'])]);

    $this->artisan('auth', ['--token' => 'valid-token'])
        ->expectsOutputToContain('Authenticated as Mathias (mathias@example.com)')
        ->assertExitCode(0);

    $config = app(Config::class);

    expect($config->storedToken())->toBe('valid-token')
        ->and(substr(sprintf('%o', fileperms($config->path())), -4))->toBe('0600');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer valid-token'));
});

test('prompts for the token when the option is omitted', function () {
    Http::fake(['*/user' => Http::response(['id' => 7, 'name' => 'Mathias', 'email' => 'mathias@example.com', 'created_at' => '2025-11-03T09:30:00.000000Z'])]);

    $this->artisan('auth')
        ->expectsQuestion('API token (create one at Settings > API Tokens)', 'prompted-token')
        ->assertExitCode(0);

    expect(app(Config::class)->storedToken())->toBe('prompted-token');
});

test('a rejected token is not saved', function () {
    Http::fake(['*/user' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $this->artisan('auth', ['--token' => 'bad-token'])
        ->expectsOutputToContain('That token was rejected by the API. Nothing was saved.')
        ->assertExitCode(1);

    expect(app(Config::class)->storedToken())->toBeNull();
});

test('an empty token is rejected without any HTTP request', function () {
    Http::fake();

    $this->artisan('auth')
        ->expectsQuestion('API token (create one at Settings > API Tokens)', '')
        ->expectsOutputToContain('No token provided.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});
