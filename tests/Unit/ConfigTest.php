<?php

use MathiasGrimm\GlimpseCli\Glimpse\Config;

describe('token', function () {
    test('returns null when nothing is configured', function () {
        expect((new Config)->token())->toBeNull();
    });

    test('reads the token from the config file', function () {
        $config = new Config;
        $config->setToken('file-token');

        expect($config->token())->toBe('file-token');
    });

    test('GLIMPSE_TOKEN env var beats the config file', function () {
        $config = new Config;
        $config->setToken('file-token');

        putenv('GLIMPSE_TOKEN=env-token');

        expect($config->token())->toBe('env-token');

        putenv('GLIMPSE_TOKEN');
    });
});

describe('apiUrl', function () {
    test('defaults to the production API url', function () {
        expect((new Config)->apiUrl())->toBe('https://glimpseimg.com/api');
    });

    test('GLIMPSE_API_URL env var overrides the default and trailing slashes are trimmed', function () {
        putenv('GLIMPSE_API_URL=https://glimpseimg.test/api/');

        expect((new Config)->apiUrl())->toBe('https://glimpseimg.test/api');

        putenv('GLIMPSE_API_URL');
    });

    test('reads api_url from the config file', function () {
        $config = new Config;

        mkdir(dirname($config->path()), 0700, true);
        file_put_contents($config->path(), json_encode(['api_url' => 'https://staging.glimpseimg.com/api']));

        expect($config->apiUrl())->toBe('https://staging.glimpseimg.com/api');
    });
});

describe('setToken', function () {
    test('creates the config file under XDG_CONFIG_HOME with restrictive permissions', function () {
        $config = new Config;
        $config->setToken('secret');

        expect($config->path())->toBe($this->configHome.'/glimpse/config.json')
            ->and(is_file($config->path()))->toBeTrue()
            ->and(substr(sprintf('%o', fileperms($config->path())), -4))->toBe('0600');
    });

    test('preserves other config keys when updating the token', function () {
        $config = new Config;

        mkdir(dirname($config->path()), 0700, true);
        file_put_contents($config->path(), json_encode(['api_url' => 'https://staging.glimpseimg.com/api']));

        $config->setToken('secret');

        expect($config->token())->toBe('secret')
            ->and($config->apiUrl())->toBe('https://staging.glimpseimg.com/api');
    });

    test('setting a null token removes it from the file', function () {
        $config = new Config;
        $config->setToken('secret');
        $config->setToken(null);

        expect($config->token())->toBeNull()
            ->and((string) file_get_contents($config->path()))->not->toContain('secret');
    });
});
