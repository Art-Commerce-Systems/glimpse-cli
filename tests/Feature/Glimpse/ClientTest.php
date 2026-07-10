<?php

use App\Enums\ImageFormat;
use App\Glimpse\ApiException;
use App\Glimpse\AuthException;
use App\Glimpse\Client;
use App\Glimpse\ValidationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Images;

beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');
});

test('convert posts the base64 envelope and returns a decoded ImageResult', function () {
    Http::fake(['*/v1/convert' => Http::response(fakeTransformResponse())]);

    $result = app(Client::class)->convert(Images::png(), ImageFormat::Jpg);

    expect($result->bytes)->toBe(Images::jpg())
        ->and($result->format)->toBe(ImageFormat::Jpg->value)
        ->and($result->mimeType)->toBe('image/jpeg')
        ->and($result->width)->toBe(1280)
        ->and($result->height)->toBe(720);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://glimpseimg.com/api/v1/convert'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['input']['type'] === 'BASE64'
            && $request['input']['data'] === Images::PNG_BASE64
            && $request['format'] === ImageFormat::Jpg->value;
    });
});

test('convert sends optimize and quality when given', function () {
    Http::fake(['*/v1/convert' => Http::response(fakeTransformResponse())]);

    app(Client::class)->convert(Images::png(), ImageFormat::Jpg, optimize: true, quality: 60);

    Http::assertSent(fn (Request $request) => $request['optimize'] === true
        && $request['quality'] === 60);
});

test('convert omits optimize and quality from the payload by default', function () {
    Http::fake(['*/v1/convert' => Http::response(fakeTransformResponse())]);

    app(Client::class)->convert(Images::png(), ImageFormat::Jpg);

    Http::assertSent(fn (Request $request) => ! array_key_exists('optimize', $request->data())
        && ! array_key_exists('quality', $request->data()));
});

test('resize sends optimize and quality when given', function () {
    Http::fake(['*/v1/resize' => Http::response(fakeTransformResponse())]);

    app(Client::class)->resize(Images::png(), width: 800, optimize: true, quality: 60);

    Http::assertSent(fn (Request $request) => $request['width'] === 800
        && $request['optimize'] === true
        && $request['quality'] === 60);
});

test('resize omits optimize and quality from the payload by default', function () {
    Http::fake(['*/v1/resize' => Http::response(fakeTransformResponse())]);

    app(Client::class)->resize(Images::png(), width: 800);

    Http::assertSent(fn (Request $request) => ! array_key_exists('optimize', $request->data())
        && ! array_key_exists('quality', $request->data()));
});

test('optimize omits quality from the payload when not given', function () {
    Http::fake(['*/v1/optimize' => Http::response(fakeTransformResponse())]);

    app(Client::class)->optimize(Images::png());

    Http::assertSent(fn (Request $request) => ! array_key_exists('quality', $request->data()));
});

test('thumbnail sends width, height, and quality when given', function () {
    Http::fake(['*/v1/thumbnail' => Http::response(fakeTransformResponse())]);

    app(Client::class)->thumbnail(Images::png(), width: 100, height: 50, quality: 42);

    Http::assertSent(fn (Request $request) => $request['width'] === 100
        && $request['height'] === 50
        && $request['quality'] === 42);
});

test('info returns the raw data object', function () {
    Http::fake(['*/v1/info' => Http::response(['data' => ['format' => 'png', 'width' => 1, 'height' => 1]])]);

    expect(app(Client::class)->info(Images::png()))
        ->toBe(['format' => 'png', 'width' => 1, 'height' => 1]);
});

test('user verifies an explicit token without touching the stored one', function () {
    Http::fake(['*/user' => Http::response(['name' => 'Mathias', 'email' => 'mathias@example.com'])]);

    $user = app(Client::class)->user('candidate-token');

    expect($user['name'])->toBe('Mathias');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://glimpseimg.com/api/user'
        && $request->hasHeader('Authorization', 'Bearer candidate-token'));
});

test('a missing token fails before any HTTP request', function () {
    putenv('GLIMPSE_TOKEN');
    Http::fake();

    expect(fn () => app(Client::class)->optimize(Images::png()))
        ->toThrow(AuthException::class, 'Not authenticated. Run: glimpse auth');

    Http::assertNothingSent();
});

test('analyze posts metadata only, with no image payload', function () {
    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

    $estimates = app(Client::class)->analyze(ImageFormat::Jpg, 2_500_000, 4032, 3024, 80);

    expect($estimates)->toHaveCount(4)
        ->and($estimates[0]['format'])->toBe('jpg')
        ->and($estimates[3]['format'])->toBe('avif');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://glimpseimg.com/api/v1/analyze'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && ! array_key_exists('input', $request->data())
            && $request['format'] === ImageFormat::Jpg->value
            && $request['size'] === 2_500_000
            && $request['width'] === 4032
            && $request['height'] === 3024
            && $request['quality'] === 80;
    });
});

test('analyze omits dimensions, quality, and sample from the payload when null', function () {
    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

    app(Client::class)->analyze(ImageFormat::Png, 1_000_000);

    Http::assertSent(fn (Request $request) => ! array_key_exists('width', $request->data())
        && ! array_key_exists('height', $request->data())
        && ! array_key_exists('quality', $request->data())
        && ! array_key_exists('sample_bpp', $request->data()));
});

test('analyze sends the sample bpp when given', function () {
    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

    app(Client::class)->analyze(ImageFormat::Jpg, 2_500_000, 4032, 3024, sampleBpp: 0.7066);

    Http::assertSent(fn (Request $request) => $request['sample_bpp'] === 0.7066);
});

test('a 401 response maps to AuthException with the auth hint', function () {
    Http::fake(['*/v1/optimize' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    expect(fn () => app(Client::class)->optimize(Images::png()))
        ->toThrow(AuthException::class, 'Invalid or missing token. Run: glimpse auth');
});

test('a 422 response maps to ValidationException carrying the errors map', function () {
    Http::fake(['*/v1/convert' => Http::response([
        'message' => 'The format field is invalid.',
        'errors' => ['format' => ['The format field is invalid.']],
    ], 422)]);

    try {
        app(Client::class)->convert(Images::png(), ImageFormat::Png);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->getMessage())->toBe('The format field is invalid.')
            ->and($e->errors)->toBe(['format' => ['The format field is invalid.']]);
    }
});

test('other failures map to ApiException with the status code', function () {
    Http::fake(['*/v1/optimize' => Http::response(['message' => 'Server Error'], 500)]);

    expect(fn () => app(Client::class)->optimize(Images::png()))
        ->toThrow(ApiException::class, 'API error (500): Server Error');
});

test('GLIMPSE_API_URL points requests at a different host', function () {
    putenv('GLIMPSE_API_URL=https://glimpseimg.test/api');
    Http::fake(['*/v1/info' => Http::response(['data' => []])]);

    app(Client::class)->info(Images::png());

    Http::assertSent(fn (Request $request) => $request->url() === 'https://glimpseimg.test/api/v1/info');
});
