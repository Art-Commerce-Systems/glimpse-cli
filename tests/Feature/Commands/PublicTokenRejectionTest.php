<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use MathiasGrimm\GlimpseCli\Glimpse\Config;

/*
 * Every command carries its own rejectPublicToken() call, so each one
 * is covered: a forgotten call would upload image bytes (or read
 * account data) just to receive the server's 403.
 */
beforeEach(function () {
    putenv('GLIMPSE_TOKEN');
    app()->instance(Config::class, new Config(publicTokenOverride: 'pub-token'));
    Http::fake();
});

test('byte-uploading commands refuse the built-in public token before calling the API', function (string $command) {
    $path = createImage();

    $exitCode = Artisan::call($command, ['input' => $path]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('The built-in public CI token only runs check and analyze.');

    Http::assertNothingSent();
})->with(['optimize', 'resize', 'thumbnail', 'info']);
