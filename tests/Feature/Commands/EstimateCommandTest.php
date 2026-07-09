<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');

    Http::fake(['*/v1/estimate' => Http::response(fakeEstimateResponse())]);
});

test('derives the payload from the local file and uploads no bytes', function () {
    $this->artisan('estimate', ['input' => createImage()])
        ->assertExitCode(0);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://glimpseimg.com/api/v1/estimate'
            && ! array_key_exists('input', $request->data())
            && $request['format'] === 'png'
            && $request['size'] === 70
            && $request['width'] === 1
            && $request['height'] === 1
            && ! array_key_exists('quality', $request->data());
    });
});

test('prints a table with one row per format', function () {
    $exitCode = Artisan::call('estimate', ['input' => createImage()]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Source')
        ->and($output)->toContain('PNG, 70 B, 1x1')
        ->and($output)->toContain('AVIF')
        ->and($output)->toContain('~459 KB')
        ->and($output)->toContain('81.2%')
        ->and($output)->toContain('-144%')
        ->and($output)->toContain('-3.4 MB');
});

test('prints the raw estimates with --json', function () {
    $exitCode = Artisan::call('estimate', ['input' => createImage(), '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($decoded)->toHaveCount(4)
        ->and($decoded[0]['format'])->toBe('jpg')
        ->and($decoded[1]['quality'])->toBeNull();
});

test('passes an explicit quality through to the api', function () {
    $this->artisan('estimate', ['input' => createImage(), '--quality' => 60])
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request['quality'] === 60);
});

test('omits dimensions when php cannot parse the format locally', function () {
    $path = createImage('photo.avif');
    file_put_contents($path, "\x00\x00\x00\x20ftypavifavifmif1miaf".str_repeat("\x00", 40));

    $this->artisan('estimate', ['input' => $path])
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request['format'] === 'avif'
        && ! array_key_exists('width', $request->data())
        && ! array_key_exists('height', $request->data()));
});

test('rejects bytes that are not a supported image', function () {
    $path = createImage('fake.png');
    file_put_contents($path, 'not an image at all');

    $this->artisan('estimate', ['input' => $path])
        ->expectsOutputToContain('Unrecognized image format')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('fails cleanly when the file does not exist', function () {
    $this->artisan('estimate', ['input' => '/nope/missing.jpg'])
        ->expectsOutputToContain('File not found')
        ->assertExitCode(1);

    Http::assertNothingSent();
});
