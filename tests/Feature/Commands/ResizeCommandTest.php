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

test('requires at least one of --width or --height before any HTTP request', function () {
    Http::fake();

    $this->artisan('resize', ['input' => createImage()])
        ->expectsOutputToContain('Provide --width and/or --height.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});
