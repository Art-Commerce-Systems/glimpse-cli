<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Images;

beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');
});

test('creates a thumbnail with API defaults and writes a .thumb output', function () {
    Http::fake(['*/v1/thumbnail' => Http::response(fakeTransformResponse())]);

    $input = createImage('photo.png');
    $expectedOutput = dirname($input).'/photo.thumb.jpg';

    $this->artisan('thumbnail', ['input' => $input])
        ->expectsOutputToContain("Wrote {$expectedOutput}")
        ->assertExitCode(0);

    expect(file_get_contents($expectedOutput))->toBe(Images::jpg());

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return ! array_key_exists('width', $data)
            && ! array_key_exists('height', $data)
            && ! array_key_exists('quality', $data);
    });
});

test('--in-place overwrites the input file without --force', function () {
    Http::fake(['*/v1/thumbnail' => Http::response(fakeTransformResponse('png', 'image/png'))]);

    $input = createImage('photo.png');

    $this->artisan('thumbnail', ['input' => $input, '--in-place' => true])
        ->expectsOutputToContain("Wrote {$input}")
        ->assertExitCode(0);

    expect(file_get_contents($input))->toBe(Images::jpg())
        ->and(file_exists(dirname($input).'/photo.thumb.png'))->toBeFalse();
});

test('records only the output in an existing baseline', function () {
    Http::fake(['*/v1/thumbnail' => Http::response(fakeTransformResponse())]);

    $input = createImage('photo.png');
    writeBaseline([]);

    $this->artisan('thumbnail', ['input' => $input])
        ->assertExitCode(0);

    expect(readBaseline()['files'])->toBe([
        'photo.thumb.jpg' => baselineEntry(dirname($input).'/photo.thumb.jpg'),
    ]);
});

test('passes width, height, and quality through to the API', function () {
    Http::fake(['*/v1/thumbnail' => Http::response(fakeTransformResponse())]);

    $this->artisan('thumbnail', [
        'input' => createImage(),
        '--width' => '100',
        '--height' => '50',
        '--quality' => '42',
    ])->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request['width'] === 100
        && $request['height'] === 50
        && $request['quality'] === 42);
});
