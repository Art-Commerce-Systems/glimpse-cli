<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Images;

beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');
});

test('converts and writes the default output path next to the input', function () {
    Http::fake(['*/v1/convert' => Http::response(fakeTransformResponse('webp', 'image/webp'))]);

    $input = createImage('photo.png');
    $expectedOutput = dirname($input).'/photo.webp';

    $this->artisan('convert', ['input' => $input, '--format' => 'webp'])
        ->expectsOutputToContain("Wrote {$expectedOutput} (image/webp,")
        ->assertExitCode(0);

    expect(file_get_contents($expectedOutput))->toBe(Images::jpg());

    Http::assertSent(fn (Request $request) => $request['format'] === 'webp'
        && $request['input']['type'] === 'BASE64'
        && $request['input']['data'] === Images::PNG_BASE64);
});

test('infers the format from the output path extension', function () {
    Http::fake(['*/v1/convert' => Http::response(fakeTransformResponse('webp', 'image/webp'))]);

    $input = createImage('photo.png');
    $output = dirname($input).'/converted.webp';

    $this->artisan('convert', ['input' => $input, '--output' => $output])
        ->assertExitCode(0);

    expect(file_get_contents($output))->toBe(Images::jpg());

    Http::assertSent(fn (Request $request) => $request['format'] === 'webp');
});

test('errors when neither --format nor a recognizable output extension is given', function () {
    Http::fake();

    $this->artisan('convert', ['input' => createImage()])
        ->expectsOutputToContain('Provide --format, or an output path with a known image extension.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('rejects an unsupported format before any HTTP request', function () {
    Http::fake();

    $this->artisan('convert', ['input' => createImage(), '--format' => 'bmp'])
        ->expectsOutputToContain('Unsupported format: bmp. Supported: jpg, png, webp, gif, avif.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('refuses to overwrite the derived output file without --force', function () {
    Http::fake(['*/v1/convert' => Http::response(fakeTransformResponse('webp', 'image/webp'))]);

    $input = createImage('photo.png');
    $existing = dirname($input).'/photo.webp';
    file_put_contents($existing, 'original');

    $this->artisan('convert', ['input' => $input, '--format' => 'webp'])
        ->expectsOutputToContain("{$existing} already exists. Use --force to overwrite.")
        ->assertExitCode(1);

    expect(file_get_contents($existing))->toBe('original');
});

test('an existing explicit output fails fast before any HTTP request', function () {
    Http::fake();

    $input = createImage('photo.png');
    $output = dirname($input).'/taken.webp';
    file_put_contents($output, 'original');

    $this->artisan('convert', ['input' => $input, '--output' => $output])
        ->expectsOutputToContain("{$output} already exists. Use --force to overwrite.")
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('overwrites the output file with --force', function () {
    Http::fake(['*/v1/convert' => Http::response(fakeTransformResponse('webp', 'image/webp'))]);

    $input = createImage('photo.png');
    $existing = dirname($input).'/photo.webp';
    file_put_contents($existing, 'original');

    $this->artisan('convert', ['input' => $input, '--format' => 'webp', '--force' => true])
        ->assertExitCode(0);

    expect(file_get_contents($existing))->toBe(Images::jpg());
});

test('--in-place replaces the input with the converted file', function () {
    Http::fake(['*/v1/convert' => Http::response(fakeTransformResponse('webp', 'image/webp'))]);

    $input = createImage('photo.png');
    $expectedOutput = dirname($input).'/photo.webp';

    $this->artisan('convert', ['input' => $input, '--format' => 'webp', '--in-place' => true])
        ->expectsOutputToContain("Wrote {$expectedOutput}")
        ->assertExitCode(0);

    expect(file_get_contents($expectedOutput))->toBe(Images::jpg())
        ->and(file_exists($input))->toBeFalse();
});

test('--in-place overwrites the input without --force when the format is unchanged', function () {
    Http::fake(['*/v1/convert' => Http::response(fakeTransformResponse('png', 'image/png'))]);

    $input = createImage('photo.png');

    $this->artisan('convert', ['input' => $input, '--format' => 'png', '--in-place' => true])
        ->expectsOutputToContain("Wrote {$input}")
        ->assertExitCode(0);

    expect(file_get_contents($input))->toBe(Images::jpg());
});

test('--in-place fails fast when the converted target already exists without --force', function () {
    Http::fake();

    $input = createImage('photo.png');
    $existing = dirname($input).'/photo.webp';
    file_put_contents($existing, 'original');

    $this->artisan('convert', ['input' => $input, '--format' => 'webp', '--in-place' => true])
        ->expectsOutputToContain("{$existing} already exists. Use --force to overwrite.")
        ->assertExitCode(1);

    expect(file_get_contents($existing))->toBe('original')
        ->and(file_exists($input))->toBeTrue();

    Http::assertNothingSent();
});

test('--in-place with --force overwrites the converted target and removes the input', function () {
    Http::fake(['*/v1/convert' => Http::response(fakeTransformResponse('webp', 'image/webp'))]);

    $input = createImage('photo.png');
    $existing = dirname($input).'/photo.webp';
    file_put_contents($existing, 'original');

    $this->artisan('convert', ['input' => $input, '--format' => 'webp', '--in-place' => true, '--force' => true])
        ->assertExitCode(0);

    expect(file_get_contents($existing))->toBe(Images::jpg())
        ->and(file_exists($input))->toBeFalse();
});

test('--in-place cannot be combined with --output', function () {
    Http::fake();

    $input = createImage('photo.png');

    $this->artisan('convert', ['input' => $input, '--format' => 'webp', '--in-place' => true, '--output' => dirname($input).'/other.webp'])
        ->expectsOutputToContain('--in-place cannot be combined with -o/--output.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('--in-place cannot be used with stdin input', function () {
    Http::fake();

    $this->artisan('convert', ['input' => '-', '--format' => 'webp', '--in-place' => true])
        ->expectsOutputToContain('--in-place cannot be used when reading from stdin.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('prints result metadata as JSON with --json', function () {
    Http::fake(['*/v1/convert' => Http::response(fakeTransformResponse('webp', 'image/webp'))]);

    $input = createImage('photo.png');
    $expectedOutput = dirname($input).'/photo.webp';

    $exitCode = Artisan::call('convert', ['input' => $input, '--format' => 'webp', '--json' => true]);

    expect($exitCode)->toBe(0)
        ->and(json_decode(Artisan::output(), true))->toBe([
            'output' => $expectedOutput,
            'format' => 'webp',
            'mime_type' => 'image/webp',
            'size' => strlen(Images::jpg()),
            'width' => 1280,
            'height' => 720,
        ]);
});

test('stdin input requires an explicit output path', function () {
    Http::fake();

    $this->artisan('convert', ['input' => '-', '--format' => 'webp'])
        ->expectsOutputToContain('Provide -o/--output when reading from stdin.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('errors on a missing input file', function () {
    Http::fake();

    $this->artisan('convert', ['input' => '/nonexistent/photo.png', '--format' => 'webp'])
        ->expectsOutputToContain('File not found: /nonexistent/photo.png')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('rejects inputs over the 15 MiB limit before any HTTP request', function () {
    Http::fake();

    $input = createImage('huge.png');
    file_put_contents($input, str_repeat('a', 15 * 1024 * 1024 + 1));

    $this->artisan('convert', ['input' => $input, '--format' => 'webp'])
        ->expectsOutputToContain('The image exceeds the 15 MiB limit.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});
