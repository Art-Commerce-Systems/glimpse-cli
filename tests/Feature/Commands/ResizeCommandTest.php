<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Images;

beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');
});

test('resizes and writes a .resized output next to the input', function () {
    Http::fake(['*/v1/resize' => Http::response(fakeTransformResponse())]);

    $input = createImage('photo.png');
    $expectedOutput = dirname($input).'/photo.resized.jpg';

    $this->artisan('resize', ['input' => $input, '--width' => '800'])
        ->expectsOutputToContain("Wrote {$expectedOutput}")
        ->assertExitCode(0);

    expect(file_get_contents($expectedOutput))->toBe(Images::jpg());

    Http::assertSent(fn (Request $request) => $request['width'] === 800
        && ! array_key_exists('height', $request->data()));
});

test('--in-place overwrites the input file without --force', function () {
    Http::fake(['*/v1/resize' => Http::response(fakeTransformResponse('png', 'image/png'))]);

    $input = createImage('photo.png');

    $this->artisan('resize', ['input' => $input, '--width' => '800', '--in-place' => true])
        ->expectsOutputToContain("Wrote {$input}")
        ->assertExitCode(0);

    expect(file_get_contents($input))->toBe(Images::jpg())
        ->and(file_exists(dirname($input).'/photo.resized.png'))->toBeFalse();
});

test('--in-place replaces the input when the API returns a different format', function () {
    Http::fake(['*/v1/resize' => Http::response(fakeTransformResponse())]);

    $input = createImage('photo.png');
    $expectedOutput = dirname($input).'/photo.jpg';

    $this->artisan('resize', ['input' => $input, '--width' => '800', '--in-place' => true])
        ->expectsOutputToContain("Wrote {$expectedOutput}")
        ->assertExitCode(0);

    expect(file_get_contents($expectedOutput))->toBe(Images::jpg())
        ->and(file_exists($input))->toBeFalse();
});

test('--optimize sends optimize without quality', function () {
    Http::fake(['*/v1/resize' => Http::response(fakeTransformResponse())]);

    $this->artisan('resize', ['input' => createImage('photo.png'), '--width' => '800', '--optimize' => true])
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request['optimize'] === true
        && ! array_key_exists('quality', $request->data()));
});

test('--optimize with --quality sends both', function () {
    Http::fake(['*/v1/resize' => Http::response(fakeTransformResponse())]);

    $this->artisan('resize', ['input' => createImage('photo.png'), '--width' => '800', '--optimize' => true, '--quality' => '70'])
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request['optimize'] === true
        && $request['quality'] === 70);
});

test('omits optimize and quality from the payload when the flags are not given', function () {
    Http::fake(['*/v1/resize' => Http::response(fakeTransformResponse())]);

    $this->artisan('resize', ['input' => createImage('photo.png'), '--width' => '800'])
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => ! array_key_exists('optimize', $request->data())
        && ! array_key_exists('quality', $request->data()));
});

test('records only the output in an existing baseline', function () {
    Http::fake(['*/v1/resize' => Http::response(fakeTransformResponse())]);

    $input = createImage('photo.png');
    writeBaseline([]);

    $this->artisan('resize', ['input' => $input, '--width' => '800'])
        ->assertExitCode(0);

    expect(readBaseline()['files'])->toBe([
        'photo.resized.jpg' => baselineEntry(dirname($input).'/photo.resized.jpg'),
    ]);
});

test('--quality without --optimize fails before any HTTP request', function () {
    Http::fake();

    $this->artisan('resize', ['input' => createImage('photo.png'), '--width' => '800', '--quality' => '70'])
        ->expectsOutputToContain('--quality requires --optimize.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('requires at least one of --width or --height before any HTTP request', function () {
    Http::fake();

    $this->artisan('resize', ['input' => createImage()])
        ->expectsOutputToContain('Provide --width and/or --height.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});
