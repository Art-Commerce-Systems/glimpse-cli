<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');
});

/**
 * Fake the estimate endpoint. The default canned response saves 81.2%
 * at best; tests exercising the passing path fake a response whose
 * best saving stays under the threshold instead. One fake per test:
 * stacked Http::fake() stubs resolve in registration order, so a
 * beforeEach default could not be overridden.
 */
function fakeEstimate(?float $bestPercent = null): void
{
    Http::fake(['*/v1/estimate' => Http::response($bestPercent === null ? fakeEstimateResponse() : ['data' => [
        ['format' => 'webp', 'size' => 68, 'saved' => 2, 'saved_percent' => $bestPercent, 'quality' => 85],
        ['format' => 'png', 'size' => 71, 'saved' => -1, 'saved_percent' => -1.4, 'quality' => null],
    ]])]);
}

test('lists images over the threshold and exits 1', function () {
    fakeEstimate();
    createImage('photo.png');
    createImage('nested/big.png');

    $exitCode = Artisan::call('check', ['input' => workspace()]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('photo.png')
        ->and($output)->toContain('nested/big.png')
        ->and($output)->toContain('70 B')
        ->and($output)->toContain('~459 KB')
        ->and($output)->toContain('AVIF')
        ->and($output)->toContain('81.2%')
        ->and($output)->toContain('2 of 2 images need optimization (threshold: 10%).');
});

test('passes when every image is within the threshold', function () {
    fakeEstimate(bestPercent: 3.2);

    createImage('photo.png');

    $exitCode = Artisan::call('check', ['input' => workspace()]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('All 1 images are within the 10% threshold.')
        ->and($output)->not->toContain('photo.png');
});

test('flags a saving exactly at the threshold', function () {
    fakeEstimate(bestPercent: 10.0);

    createImage('photo.png');

    expect(Artisan::call('check', ['input' => workspace()]))->toBe(1);
});

test('respects a custom --threshold', function () {
    fakeEstimate();
    createImage('photo.png');

    expect(Artisan::call('check', ['input' => workspace(), '--threshold' => 90]))->toBe(0)
        ->and(Artisan::call('check', ['input' => workspace(), '--threshold' => 50]))->toBe(1);
});

test('rejects invalid thresholds before calling the api', function (string $threshold) {
    fakeEstimate();
    createImage('photo.png');

    $this->artisan('check', ['input' => workspace(), '--threshold' => $threshold])
        ->expectsOutputToContain('The --threshold option must be a number between 0 and 100.')
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with(['abc', '-5', '150']);

test('checks a single file by its basename', function () {
    fakeEstimate();
    $path = createImage('photo.png');

    $exitCode = Artisan::call('check', ['input' => $path]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('photo.png')
        ->and($output)->toContain('1 of 1 images need optimization');
});

test('fails cleanly when the path does not exist', function () {
    fakeEstimate();
    $this->artisan('check', ['input' => '/nope/missing.jpg'])
        ->expectsOutputToContain('File not found: /nope/missing.jpg')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('emits a json report when failing', function () {
    fakeEstimate();
    createImage('photo.png');

    $exitCode = Artisan::call('check', ['input' => workspace(), '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(1)
        ->and($decoded['threshold'])->toEqual(10)
        ->and($decoded['total'])->toBe(1)
        ->and($decoded['needs_optimization'])->toBe(1)
        ->and($decoded['files'][0]['file'])->toBe('photo.png')
        ->and($decoded['files'][0]['format'])->toBe('avif')
        ->and($decoded['files'][0]['saved_percent'])->toBe(81.2)
        ->and($decoded['failed'])->toBe([]);
});

test('emits a json report when passing', function () {
    fakeEstimate(bestPercent: 3.2);

    createImage('photo.png');

    $exitCode = Artisan::call('check', ['input' => workspace(), '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($decoded['total'])->toBe(1)
        ->and($decoded['needs_optimization'])->toBe(0)
        ->and($decoded['files'])->toBe([]);
});

test('fails when a file cannot be checked', function () {
    fakeEstimate(bestPercent: 3.2);

    createImage('good.png');
    createImage('bad.png', 'not an image');

    $exitCode = Artisan::call('check', ['input' => workspace()]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('1 file(s) could not be checked:')
        ->and($output)->toContain('bad.png')
        ->and($output)->toContain('Unrecognized image format.');
});

test('passes when the directory contains no images', function () {
    fakeEstimate();
    mkdir(workspace(), 0755, true);

    $exitCode = Artisan::call('check', ['input' => workspace()]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('No images found in');

    Http::assertNothingSent();
});

test('passes when .glimpseignore excludes every offender', function () {
    fakeEstimate();
    createImage('photo.png');
    file_put_contents(workspace().'/.glimpseignore', "*.png\n");

    $exitCode = Artisan::call('check', ['input' => workspace()]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('No images found in');

    Http::assertNothingSent();
});

test('fails with an auth error when no token is configured', function () {
    fakeEstimate();
    putenv('GLIMPSE_TOKEN');

    createImage('photo.png');

    $exitCode = Artisan::call('check', ['input' => workspace()]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Not authenticated. Run: glimpse auth');

    Http::assertNothingSent();
});
