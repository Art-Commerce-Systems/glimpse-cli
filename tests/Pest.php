<?php

use Illuminate\Support\Facades\File;
use MathiasGrimm\GlimpseCli\Glimpse\Config;
use MathiasGrimm\GlimpseCli\Support\BaselineFile;
use MathiasGrimm\GlimpseCli\Support\Sleeper;
use Tests\Fixtures\Images;
use Tests\TestCase;

uses(TestCase::class)
    ->beforeEach(function () {
        $this->configHome = sys_get_temp_dir().'/glimpse-cli-test-'.bin2hex(random_bytes(6));
        $this->originalCwd = (string) getcwd();

        putenv('XDG_CONFIG_HOME='.$this->configHome);
        putenv('GLIMPSE_TOKEN');
        putenv('GLIMPSE_API_URL');

        // A real public token is baked into Config for releases. Tests
        // must not silently fall back to it: run with the fallback off,
        // and let tests that exercise it bind their own override.
        $this->app->instance(Config::class, new Config(publicTokenOverride: ''));
    })
    ->afterEach(function () {
        if ($this->originalCwd !== '') {
            chdir($this->originalCwd);
        }

        if ($this->configHome !== '' && is_dir($this->configHome)) {
            File::deleteDirectory($this->configHome);
        }

        putenv('XDG_CONFIG_HOME');
        putenv('GLIMPSE_TOKEN');
        putenv('GLIMPSE_API_URL');
    })
    ->in('Feature', 'Unit');

/**
 * The directory createImage() writes fixtures into.
 */
function workspace(): string
{
    return test()->configHome.'/workspace';
}

/**
 * Write an image fixture into the test workspace and return its path.
 * Nested names create the intermediate directories; custom contents
 * allow planting corrupt or non-PNG files.
 */
function createImage(string $name = 'photo.png', ?string $contents = null): string
{
    $path = workspace().'/'.$name;

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, $contents ?? Images::png());

    return $path;
}

/**
 * Make the test workspace the current working directory, creating it if
 * needed. The baseline is anchored on the CWD, so baseline tests run from
 * the workspace the way a user runs glimpse from their project root. The
 * suite's afterEach restores the original CWD.
 */
function chdirWorkspace(?string $directory = null): void
{
    $directory ??= workspace();

    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    chdir($directory);
}

/**
 * The path of the .glimpse-baseline.json in the directory (the test
 * workspace by default).
 */
function baselinePath(?string $directory = null): string
{
    return ($directory ?? workspace()).'/'.BaselineFile::FILENAME;
}

/**
 * Write a .glimpse-baseline.json into the directory (the test workspace by
 * default), empty unless entries are given. Entries map relative paths to
 * size/xxh128/via records; build current-content entries with
 * baselineEntry().
 *
 * @param  array<string, array{size: int, xxh128: string, via: string}>  $files
 */
function writeBaseline(array $files = [], ?string $directory = null): void
{
    $directory ??= workspace();

    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    file_put_contents(
        baselinePath($directory),
        json_encode([
            '_readme' => BaselineFile::README,
            'files' => $files === [] ? new stdClass : $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
    );
}

/**
 * Plant an unparseable .glimpse-baseline.json in the directory (the test
 * workspace by default).
 */
function writeMalformedBaseline(?string $directory = null): void
{
    $directory ??= workspace();

    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    file_put_contents(baselinePath($directory), '{nope');
}

/**
 * The baseline entry matching a file's current content, recorded by the
 * given command.
 *
 * @return array{size: int, xxh128: string, via: string}
 */
function baselineEntry(string $path, string $via = 'analyze'): array
{
    return ['size' => (int) filesize($path), 'xxh128' => (string) hash_file('xxh128', $path), 'via' => $via];
}

/**
 * The files map of the .glimpse-baseline.json in the directory (the test
 * workspace by default).
 *
 * @return array<string, array{size: int, xxh128: string, via: string}>
 */
function baselineFiles(?string $directory = null): array
{
    return json_decode((string) file_get_contents(baselinePath($directory)), true)['files'];
}

/**
 * Swap the container's Sleeper for one that records requested delays
 * instead of sleeping. Read its $delays after the run.
 */
function fakeSleeper(): object
{
    $sleeper = new class extends Sleeper
    {
        /** @var list<int> */
        public array $delays = [];

        public function sleep(int $seconds): void
        {
            $this->delays[] = $seconds;
        }
    };

    app()->instance(Sleeper::class, $sleeper);

    return $sleeper;
}

/**
 * A canned successful analyze-endpoint response envelope.
 *
 * @return array{data: list<array<string, mixed>>}
 */
function fakeAnalyzeResponse(): array
{
    return ['data' => [
        ['format' => 'jpg', 'size' => 812000, 'saved' => 1688000, 'saved_percent' => 67.5, 'quality' => 85],
        ['format' => 'png', 'size' => 6100000, 'saved' => -3600000, 'saved_percent' => -144.0, 'quality' => null],
        ['format' => 'webp', 'size' => 590000, 'saved' => 1910000, 'saved_percent' => 76.4, 'quality' => 85],
        ['format' => 'avif', 'size' => 470000, 'saved' => 2030000, 'saved_percent' => 81.2, 'quality' => 85],
    ]];
}

/**
 * A canned successful transform-endpoint response envelope.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{data: array<string, mixed>}
 */
function fakeTransformResponse(string $format = 'jpg', string $mimeType = 'image/jpeg', array $overrides = []): array
{
    return ['data' => $overrides + [
        'output' => ['type' => 'BASE64', 'data' => Images::JPG_BASE64],
        'format' => $format,
        'mime_type' => $mimeType,
        'size' => strlen(Images::jpg()),
        'width' => 1280,
        'height' => 720,
    ]];
}

/**
 * Fake a transform endpoint (convert, optimize, resize, thumbnail) with a
 * canned successful response reporting the given output format.
 *
 * @param  array<string, mixed>  $overrides
 */
function fakeTransform(string $endpoint, string $format = 'jpg', array $overrides = []): void
{
    $mimeType = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'avif' => 'image/avif',
    ][$format];

    Http::fake(["*/v1/{$endpoint}" => Http::response(fakeTransformResponse($format, $mimeType, $overrides))]);
}
