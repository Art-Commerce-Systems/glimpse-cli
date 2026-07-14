<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Images;

beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');
});

test('optimizes and writes a .optimized output next to the input', function () {
    Http::fake(['*/v1/optimize' => Http::response(fakeTransformResponse())]);

    $input = createImage('photo.png');
    $expectedOutput = dirname($input).'/photo.optimized.jpg';

    $this->artisan('optimize', ['input' => $input, '--quality' => '70'])
        ->expectsOutputToContain("Wrote {$expectedOutput}")
        ->assertExitCode(0);

    expect(file_get_contents($expectedOutput))->toBe(Images::jpg());

    Http::assertSent(fn (Request $request) => $request['quality'] === 70);
});

test('--in-place overwrites the input file without --force', function () {
    Http::fake(['*/v1/optimize' => Http::response(fakeTransformResponse('png', 'image/png'))]);

    $input = createImage('photo.png');

    $this->artisan('optimize', ['input' => $input, '--in-place' => true])
        ->expectsOutputToContain("Wrote {$input}")
        ->assertExitCode(0);

    expect(file_get_contents($input))->toBe(Images::jpg())
        ->and(file_exists(dirname($input).'/photo.optimized.png'))->toBeFalse();
});

test('records the source and output in an existing baseline', function () {
    Http::fake(['*/v1/optimize' => Http::response(fakeTransformResponse())]);

    $input = createImage('photo.png');
    writeBaseline([]);

    $this->artisan('optimize', ['input' => $input])
        ->assertExitCode(0);

    expect(readBaseline()['files'])->toBe([
        'photo.optimized.jpg' => baselineEntry(dirname($input).'/photo.optimized.jpg'),
        'photo.png' => baselineEntry($input),
    ]);
});

test('--in-place records the overwritten input with its new content', function () {
    Http::fake(['*/v1/optimize' => Http::response(fakeTransformResponse('png', 'image/png'))]);

    $input = createImage('photo.png');
    writeBaseline([]);

    $this->artisan('optimize', ['input' => $input, '--in-place' => true])
        ->assertExitCode(0);

    expect(readBaseline()['files'])->toBe([
        'photo.png' => baselineEntry($input),
    ]);
});

test('creates no baseline when none exists', function () {
    Http::fake(['*/v1/optimize' => Http::response(fakeTransformResponse())]);

    $input = createImage('photo.png');
    mkdir(workspace().'/.git');

    $this->artisan('optimize', ['input' => $input])
        ->assertExitCode(0);

    expect(file_exists(workspace().'/.glimpse-baseline.json'))->toBeFalse();
});

test('rejects a non-numeric quality before any HTTP request', function () {
    Http::fake();

    $this->artisan('optimize', ['input' => createImage(), '--quality' => 'high'])
        ->expectsOutputToContain('The --quality option must be a number.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});
