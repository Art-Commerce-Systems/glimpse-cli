<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');

    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);
});

test('derives the payload from the local file and uploads no bytes', function () {
    $this->artisan('analyze', ['input' => createImage()])
        ->assertExitCode(0);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://glimpseimg.com/api/v1/analyze'
            && ! array_key_exists('input', $request->data())
            && $request['format'] === 'png'
            && $request['size'] === 70
            && $request['width'] === 1
            && $request['height'] === 1
            && is_float($request['sample_bpp'])
            && $request['sample_bpp'] > 0
            && ! array_key_exists('quality', $request->data());
    });
});

test('mentions the sample in the source line', function () {
    $exitCode = Artisan::call('analyze', ['input' => createImage()]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain(', sampled');
});

test('prints a table with one row per format', function () {
    $exitCode = Artisan::call('analyze', ['input' => createImage()]);
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

test('prints the raw analysis with --json', function () {
    $exitCode = Artisan::call('analyze', ['input' => createImage(), '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($decoded)->toHaveCount(4)
        ->and($decoded[0]['format'])->toBe('jpg')
        ->and($decoded[1]['quality'])->toBeNull();
});

test('passes an explicit quality through to the api', function () {
    $this->artisan('analyze', ['input' => createImage(), '--quality' => 60, '--optimize' => true])
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request['quality'] === 60);
});

test('requires --optimize when --quality is set', function () {
    $this->artisan('analyze', ['input' => createImage(), '--quality' => 60])
        ->expectsOutputToContain('--quality requires --optimize.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('omits dimensions when php cannot parse the format locally', function () {
    $path = createImage('photo.avif');
    file_put_contents($path, "\x00\x00\x00\x20ftypavifavifmif1miaf".str_repeat("\x00", 40));

    $this->artisan('analyze', ['input' => $path])
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request['format'] === 'avif'
        && ! array_key_exists('width', $request->data())
        && ! array_key_exists('height', $request->data()));
});

test('rejects bytes that are not a supported image', function () {
    $path = createImage('fake.png');
    file_put_contents($path, 'not an image at all');

    $this->artisan('analyze', ['input' => $path])
        ->expectsOutputToContain('Unrecognized image format')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('fails cleanly when the file does not exist', function () {
    $this->artisan('analyze', ['input' => '/nope/missing.jpg'])
        ->expectsOutputToContain('File not found')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('rejects an unsupported --format', function () {
    $this->artisan('analyze', ['input' => createImage(), '--format' => 'bogus'])
        ->expectsOutputToContain('Unsupported format: bogus')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('filters the single-file table to the --format target', function () {
    $exitCode = Artisan::call('analyze', ['input' => createImage(), '--format' => 'webp']);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('WEBP')
        ->and($output)->toContain('~576.2 KB')
        ->and($output)->not->toContain('AVIF');
});

test('filters the single-file json to the --format target', function () {
    $exitCode = Artisan::call('analyze', ['input' => createImage(), '--format' => 'webp', '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($decoded)->toHaveCount(1)
        ->and($decoded[0]['format'])->toBe('webp');
});

test('fails when the response lacks the --format target for a single file', function () {
    $this->artisan('analyze', ['input' => createImage(), '--format' => 'gif'])
        ->expectsOutputToContain('No estimate for GIF.')
        ->assertExitCode(1);
});

test('scans a directory recursively and prints a row per image plus a totalizer', function () {
    createImage('a.png');
    createImage('nested/deep/b.png');

    $exitCode = Artisan::call('analyze', ['input' => workspace()]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('a.png')
        ->and($output)->toContain('nested/deep/b.png')
        ->and($output)->toContain('Total: 2 files');
});

test('directory scans respect .glimpseignore', function () {
    createImage('a.png');
    createImage('ignored/b.png');

    file_put_contents(workspace().'/.glimpseignore', "ignored/\n");

    $exitCode = Artisan::call('analyze', ['input' => workspace()]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('a.png')
        ->and($output)->not->toContain('ignored/b.png')
        ->and($output)->toContain('Total: 1 files');
});

test('shows only the format that saves the most when --format is omitted', function () {
    createImage('a.png');

    $exitCode = Artisan::call('analyze', ['input' => workspace()]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('AVIF')
        ->and($output)->toContain('~459 KB')
        ->and($output)->not->toContain('WEBP');
});

test('filters directory results to the --format target', function () {
    createImage('a.png');

    $exitCode = Artisan::call('analyze', ['input' => workspace(), '--format' => 'webp']);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('WEBP')
        ->and($output)->not->toContain('AVIF');
});

test('marks files skipped when the response lacks the --format target', function () {
    createImage('a.png');

    $exitCode = Artisan::call('analyze', ['input' => workspace(), '--format' => 'gif']);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('skipped: No estimate for GIF.');
});

test('skips corrupt files and reports them in the totalizer', function () {
    createImage('good.png');
    createImage('bad.png', 'not an image');

    $exitCode = Artisan::call('analyze', ['input' => workspace()]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('skipped: Unrecognized image format.')
        ->and($output)->toContain('Total: 2 files, 1 failed');
});

test('fails when every file in the directory fails', function () {
    createImage('bad.png', 'not an image');

    $exitCode = Artisan::call('analyze', ['input' => workspace()]);

    expect($exitCode)->toBe(1);

    Http::assertNothingSent();
});

test('requires --optimize when --quality is set on a directory', function () {
    createImage();

    $this->artisan('analyze', ['input' => workspace(), '--quality' => 60])
        ->expectsOutputToContain('--quality requires --optimize.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('fails when the directory contains no images', function () {
    mkdir(workspace(), 0755, true);

    $this->artisan('analyze', ['input' => workspace()])
        ->expectsOutputToContain('No image files found')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('prints a batch json object with files and totals', function () {
    createImage('good.png');
    createImage('bad.png', 'not an image');

    $exitCode = Artisan::call('analyze', ['input' => workspace(), '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($decoded['files'])->toHaveCount(2)
        ->and($decoded['files'][0]['file'])->toBe('good.png')
        ->and($decoded['files'][0]['format'])->toBe('avif')
        ->and($decoded['files'][1]['error'])->toBe('Unrecognized image format.')
        ->and($decoded['totals']['files'])->toBe(2)
        ->and($decoded['totals']['failed'])->toBe(1)
        ->and($decoded['totals']['source_size'])->toBe(70);
});
