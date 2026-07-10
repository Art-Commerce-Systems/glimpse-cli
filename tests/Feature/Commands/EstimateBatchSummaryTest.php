<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * These tests need a different estimate per file, so the fake keys the
 * response on the request's size field. The fixture files are a PNG
 * magic number padded to the wanted byte count; the CLI cannot decode
 * them locally, which is fine, the payload still carries the size.
 */
beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');

    Http::fake(['*/v1/estimate' => function (Request $request) {
        $estimates = [
            5000 => ['size' => 1000, 'saved' => 4000, 'saved_percent' => 80.0],
            1000 => ['size' => 900, 'saved' => 100, 'saved_percent' => 10.0],
            300 => ['size' => 800, 'saved' => -500, 'saved_percent' => -166.7],
        ];

        return Http::response(['data' => [
            ['format' => 'avif', 'quality' => 85] + $estimates[$request['size']],
        ]]);
    }]);
});

function createSizedImage(string $name, int $bytes): string
{
    return createImage($name, str_pad("\x89PNG\r\n\x1A\n", $bytes, "\x00"));
}

test('sorts the summary by bytes saved with failed files last', function () {
    createSizedImage('a-grower.png', 300);
    createSizedImage('m-small-saver.png', 1000);
    createSizedImage('z-big-saver.png', 5000);
    createImage('b-corrupt.png', 'not an image');

    $exitCode = Artisan::call('estimate', ['input' => workspace()]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and(strpos($output, 'z-big-saver.png'))->toBeLessThan(strpos($output, 'm-small-saver.png'))
        ->and(strpos($output, 'm-small-saver.png'))->toBeLessThan(strpos($output, 'a-grower.png'))
        ->and(strpos($output, 'a-grower.png'))->toBeLessThan(strpos($output, 'b-corrupt.png'));
});

test('classifies rows green, yellow, and red', function () {
    createSizedImage('grower.png', 300);
    createSizedImage('small-saver.png', 1000);
    createSizedImage('big-saver.png', 5000);
    createImage('corrupt.png', 'not an image');

    $buffer = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: true);
    $exitCode = Artisan::call('estimate', ['input' => workspace()], $buffer);
    $output = $buffer->fetch();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain("\e[32mbig-saver.png")
        ->and($output)->toContain("\e[33msmall-saver.png")
        ->and($output)->toContain("\e[31mgrower.png")
        ->and($output)->toContain("\e[31mcorrupt.png");
});

test('repeats the header every 24 rows on long listings', function () {
    foreach (range(1, 25) as $i) {
        createSizedImage(sprintf('photo-%02d.png', $i), 1000);
    }

    Artisan::call('estimate', ['input' => workspace()]);

    expect(substr_count(Artisan::output(), 'Estimated'))->toBe(2);
});

test('does not repeat the header on short listings', function () {
    createSizedImage('photo.png', 1000);

    Artisan::call('estimate', ['input' => workspace()]);

    expect(substr_count(Artisan::output(), 'Estimated'))->toBe(1);
});

test('sorts the batch json by bytes saved as well', function () {
    createSizedImage('a-grower.png', 300);
    createSizedImage('z-big-saver.png', 5000);

    Artisan::call('estimate', ['input' => workspace(), '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['files'][0]['file'])->toBe('z-big-saver.png')
        ->and($decoded['files'][1]['file'])->toBe('a-grower.png');
});
