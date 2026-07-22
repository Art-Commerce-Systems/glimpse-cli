<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Images;

beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');
});

test('resizes and writes a .resized output next to the input', function () {
    fakeTransform('resize');

    $input = createImage('photo.png');
    $expectedOutput = dirname($input).'/photo.resized.jpg';

    $this->artisan('resize', ['input' => $input, '--width' => '800'])
        ->expectsOutputToContain("Wrote {$expectedOutput}")
        ->assertExitCode(0);

    expect(file_get_contents($expectedOutput))->toBe(Images::jpg());

    Http::assertSent(fn (Request $request) => $request['width'] === 800
        && ! array_key_exists('height', $request->data()));
});

test('never carries a psnr key, since the resize response has none', function () {
    fakeTransform('resize');

    $input = createImage('photo.png');
    $expectedOutput = dirname($input).'/photo.resized.jpg';

    $exitCode = Artisan::call('resize', ['input' => $input, '--width' => '800', '--json' => true]);

    expect($exitCode)->toBe(0)
        ->and(json_decode(Artisan::output(), true))->toBe([
            'output' => $expectedOutput,
            'format' => 'jpg',
            'mime_type' => 'image/jpeg',
            'size' => strlen(Images::jpg()),
            'width' => 1280,
            'height' => 720,
        ]);
});

test('--in-place overwrites the input file without --force', function () {
    fakeTransform('resize', 'png');

    $input = createImage('photo.png');

    $this->artisan('resize', ['input' => $input, '--width' => '800', '--in-place' => true])
        ->expectsOutputToContain("Wrote {$input}")
        ->assertExitCode(0);

    expect(file_get_contents($input))->toBe(Images::jpg())
        ->and(file_exists(dirname($input).'/photo.resized.png'))->toBeFalse();
});

test('--in-place replaces the input when the API returns a different format', function () {
    fakeTransform('resize');

    $input = createImage('photo.png');
    $expectedOutput = dirname($input).'/photo.jpg';

    $this->artisan('resize', ['input' => $input, '--width' => '800', '--in-place' => true])
        ->expectsOutputToContain("Wrote {$expectedOutput}")
        ->assertExitCode(0);

    expect(file_get_contents($expectedOutput))->toBe(Images::jpg())
        ->and(file_exists($input))->toBeFalse();
});

test('--optimize sends optimize without quality', function () {
    fakeTransform('resize');

    $this->artisan('resize', ['input' => createImage('photo.png'), '--width' => '800', '--optimize' => true])
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request['optimize'] === true
        && ! array_key_exists('quality', $request->data()));
});

test('--optimize with --quality sends both', function () {
    fakeTransform('resize');

    $this->artisan('resize', ['input' => createImage('photo.png'), '--width' => '800', '--optimize' => true, '--quality' => '70'])
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request['optimize'] === true
        && $request['quality'] === 70);
});

test('omits optimize and quality from the payload when the flags are not given', function () {
    fakeTransform('resize');

    $this->artisan('resize', ['input' => createImage('photo.png'), '--width' => '800'])
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => ! array_key_exists('optimize', $request->data())
        && ! array_key_exists('quality', $request->data()));
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
