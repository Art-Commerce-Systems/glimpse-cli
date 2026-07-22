<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Images;

beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');
});

test('optimizes and writes a .optimized output next to the input', function () {
    fakeTransform('optimize');

    $input = createImage('photo.png');
    $expectedOutput = dirname($input).'/photo.optimized.jpg';

    $this->artisan('optimize', ['input' => $input, '--quality' => '70'])
        ->expectsOutputToContain("Wrote {$expectedOutput}")
        ->assertExitCode(0);

    expect(file_get_contents($expectedOutput))->toBe(Images::jpg());

    Http::assertSent(fn (Request $request) => $request['quality'] === 70);
});

test('appends the psnr to the summary when the API reports one', function () {
    fakeTransform('optimize', 'jpg', ['psnr' => 43.75]);

    $input = createImage('photo.png');
    $expectedOutput = dirname($input).'/photo.optimized.jpg';

    $this->artisan('optimize', ['input' => $input])
        ->expectsOutputToContain("Wrote {$expectedOutput} (image/jpeg, ".strlen(Images::jpg()).' B, 1280x720, PSNR 43.75 dB)')
        ->assertExitCode(0);
});

test('--in-place overwrites the input file without --force', function () {
    fakeTransform('optimize', 'png');

    $input = createImage('photo.png');

    $this->artisan('optimize', ['input' => $input, '--in-place' => true])
        ->expectsOutputToContain("Wrote {$input}")
        ->assertExitCode(0);

    expect(file_get_contents($input))->toBe(Images::jpg())
        ->and(file_exists(dirname($input).'/photo.optimized.png'))->toBeFalse();
});

test('--in-place records the overwritten input with its new content', function () {
    chdirWorkspace();
    fakeTransform('optimize', 'png');

    $input = createImage('photo.png');
    writeBaseline();

    $this->artisan('optimize', ['input' => $input, '--in-place' => true])
        ->assertExitCode(0);

    expect(baselineFiles())->toBe([
        'photo.png' => baselineEntry($input, 'optimize'),
    ]);
});

test('rejects a non-numeric quality before any HTTP request', function () {
    Http::fake();

    $this->artisan('optimize', ['input' => createImage(), '--quality' => 'high'])
        ->expectsOutputToContain('The --quality option must be a number.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});
