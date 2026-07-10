<?php

use GlimpseImg\AuthException;
use GlimpseImg\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Images;

test('the container client resolves the env token on every request', function () {
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake(['*/v1/info' => Http::response(['data' => []])]);

    app(Client::class)->info(Images::png());

    Http::assertSent(fn (Request $request) => $request->url() === 'https://glimpseimg.com/api/v1/info'
        && $request->hasHeader('Authorization', 'Bearer test-token'));
});

test('GLIMPSE_API_URL points the container client at a different host', function () {
    putenv('GLIMPSE_TOKEN=test-token');
    putenv('GLIMPSE_API_URL=https://glimpseimg.test/api');
    Http::fake(['*/v1/info' => Http::response(['data' => []])]);

    app(Client::class)->info(Images::png());

    Http::assertSent(fn (Request $request) => $request->url() === 'https://glimpseimg.test/api/v1/info');
});

test('a missing token fails before any HTTP request', function () {
    Http::fake();

    expect(fn () => app(Client::class)->optimize(Images::png()))
        ->toThrow(AuthException::class, 'Not authenticated.');

    Http::assertNothingSent();
});
