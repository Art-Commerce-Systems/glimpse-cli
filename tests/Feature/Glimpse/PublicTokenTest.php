<?php

use MathiasGrimm\GlimpseCli\Glimpse\Config;

test('the built-in public token is the last resort', function () {
    $config = new Config(publicTokenOverride: 'pub-token');

    expect($config->token())->toBe('pub-token')
        ->and($config->usingPublicToken())->toBeTrue();
});

test('the GLIMPSE_TOKEN env variable wins over the public token', function () {
    putenv('GLIMPSE_TOKEN=user-token');

    $config = new Config(publicTokenOverride: 'pub-token');

    expect($config->token())->toBe('user-token')
        ->and($config->usingPublicToken())->toBeFalse();
});

test('a stored token wins over the public token', function () {
    $config = new Config(publicTokenOverride: 'pub-token');
    $config->setToken('stored-token');

    expect($config->token())->toBe('stored-token')
        ->and($config->usingPublicToken())->toBeFalse();
});

test('without a baked public token the fallback stays off', function () {
    $config = new Config;

    expect($config->token())->toBeNull()
        ->and($config->publicToken())->toBeNull()
        ->and($config->usingPublicToken())->toBeFalse();
});
