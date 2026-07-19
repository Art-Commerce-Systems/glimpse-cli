<?php

use App\Support\BaselineFile;
use App\Support\IgnoreFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

const INIT_SEED_QUESTION = 'Scan the current directory and record every image into the baseline now (runs analyze . --update-baseline)?';

function ignorePath(): string
{
    return workspace().'/'.IgnoreFile::FILENAME;
}

test('scaffolds the starter ignore file and an empty baseline with zero prerequisites', function () {
    chdirWorkspace();
    Http::fake();

    expect(Artisan::call('init'))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('Created '.IgnoreFile::FILENAME.'.')
        ->and($output)->toContain('Created '.BaselineFile::FILENAME.' (empty).');

    $ignore = (string) file_get_contents(ignorePath());

    expect($ignore)->toContain('vendor/')
        ->and($ignore)->toContain('node_modules/')
        ->and($ignore)->toContain('storage/')
        ->and($ignore)->toContain('apple-touch-icon*.png');

    $baseline = json_decode((string) file_get_contents(baselinePath()), true);

    expect($baseline['_readme'])->toBe(BaselineFile::README)
        ->and($baseline['files'])->toBe([]);

    Http::assertNothingSent();
});

test('confirming the prompt seeds the baseline through analyze', function () {
    chdirWorkspace();
    createImage('photo.png');
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

    $this->artisan('init')
        ->expectsConfirmation(INIT_SEED_QUESTION, 'yes')
        ->assertExitCode(0);

    expect(baselineFiles())->toBe(['photo.png' => baselineEntry(workspace().'/photo.png')]);

    Http::assertSentCount(1);
});

test('declining the prompt writes an empty baseline without touching the API', function () {
    chdirWorkspace();
    createImage('photo.png');
    Http::fake();

    $this->artisan('init')
        ->expectsConfirmation(INIT_SEED_QUESTION)
        ->assertExitCode(0);

    expect(baselineFiles())->toBe([]);

    Http::assertNothingSent();
});

test('--update-baseline seeds without prompting', function () {
    chdirWorkspace();
    createImage('photo.png');
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

    expect(Artisan::call('init', ['--update-baseline' => true]))->toBe(0)
        ->and(baselineFiles())->toBe(['photo.png' => baselineEntry(workspace().'/photo.png')]);
});

test('the ignore file is written before the seed scan, so the template already applies', function () {
    chdirWorkspace();
    createImage('vendor/lib/pic.png');
    createImage('photo.png');
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

    expect(Artisan::call('init', ['--update-baseline' => true]))->toBe(0)
        ->and(baselineFiles())->toBe(['photo.png' => baselineEntry(workspace().'/photo.png')]);

    Http::assertSentCount(1);
});

test('re-running on a configured project keeps both files and exits 0', function () {
    chdirWorkspace();
    Http::fake();
    Artisan::call('init');

    $ignoreBefore = (string) file_get_contents(ignorePath());
    $baselineBefore = (string) file_get_contents(baselinePath());

    $this->artisan('init')
        ->expectsOutputToContain(IgnoreFile::FILENAME.' already exists, kept (use --force to recreate it from the starter template).')
        ->expectsOutputToContain(BaselineFile::FILENAME.' already exists, kept.')
        ->assertExitCode(0);

    expect((string) file_get_contents(ignorePath()))->toBe($ignoreBefore)
        ->and((string) file_get_contents(baselinePath()))->toBe($baselineBefore);

    Http::assertNothingSent();
});

test('creates only the missing file when the other already exists', function () {
    chdirWorkspace();
    Http::fake();
    file_put_contents(ignorePath(), "custom-pattern/\n");

    expect(Artisan::call('init'))->toBe(0)
        ->and((string) file_get_contents(ignorePath()))->toBe("custom-pattern/\n")
        ->and(baselineFiles())->toBe([]);

    Http::assertNothingSent();
});

test('--force recreates the ignore file from the template and leaves a populated baseline untouched', function () {
    chdirWorkspace();
    Http::fake();
    file_put_contents(ignorePath(), "hand-edited/\n");
    $entry = baselineEntry(createImage('photo.png'));
    writeBaseline(['photo.png' => $entry]);

    expect(Artisan::call('init', ['--force' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Recreated '.IgnoreFile::FILENAME.' from the starter template.');

    $ignore = (string) file_get_contents(ignorePath());

    expect($ignore)->toContain('vendor/')
        ->and($ignore)->not->toContain('hand-edited/')
        ->and(baselineFiles())->toBe(['photo.png' => $entry]);

    Http::assertNothingSent();
});

test('--update-baseline refreshes an existing baseline through analyze without prompting', function () {
    chdirWorkspace();
    $path = createImage('photo.png');
    $stale = baselineEntry($path);
    $stale['xxh128'] = 'stale';
    writeBaseline(['photo.png' => $stale, 'gone.png' => ['size' => 1, 'xxh128' => 'x', 'via' => 'analyze']]);
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

    $this->artisan('init', ['--update-baseline' => true])
        ->assertExitCode(0);

    expect(baselineFiles())->toBe(['photo.png' => baselineEntry($path)]);
});

test('a failed seed still scaffolds an empty baseline and exits 1 with a retry hint', function () {
    chdirWorkspace();
    createImage('photo.png');
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake(['*/v1/analyze' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    expect(Artisan::call('init', ['--update-baseline' => true]))->toBe(1);

    $output = Artisan::output();

    expect($output)->toContain('The scan failed; wrote an empty '.BaselineFile::FILENAME.' instead. After fixing the problem, run: glimpse analyze . --update-baseline')
        ->and(is_file(ignorePath()))->toBeTrue()
        ->and(baselineFiles())->toBe([]);
});

test('a partially failed seed passes analyze exit 0 through and drops the seed hint', function () {
    chdirWorkspace();
    createImage('photo.png');
    createImage('broken.png', 'not an image');
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

    expect(Artisan::call('init', ['--update-baseline' => true]))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('skipped: Unrecognized image format.')
        ->and($output)->not->toContain('Accept the current images as already handled')
        ->and(baselineFiles())->toBe(['photo.png' => baselineEntry(workspace().'/photo.png')]);
});

test('the seed-hint state does not leak between runs in the same process', function () {
    chdirWorkspace();
    createImage('photo.png');
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

    Artisan::call('init', ['--update-baseline' => true]);
    Artisan::output();

    chdirWorkspace(workspace().'/fresh');

    expect(Artisan::call('init'))->toBe(0)
        ->and(Artisan::output())->toContain('Accept the current images as already handled');
});

test('next steps include the seed hint on a scaffold-only run', function () {
    chdirWorkspace();
    Http::fake();

    Artisan::call('init');
    $output = Artisan::output();

    expect($output)->toContain('Next steps:')
        ->and($output)->toContain('Accept the current images as already handled: glimpse analyze . --update-baseline')
        ->and($output)->toContain('Commit '.IgnoreFile::FILENAME.' and '.BaselineFile::FILENAME.'.')
        ->and($output)->toContain('glimpse check .');
});

test('next steps drop the seed hint when the baseline was seeded', function () {
    chdirWorkspace();
    createImage('photo.png');
    putenv('GLIMPSE_TOKEN=test-token');
    Http::fake(['*/v1/analyze' => Http::response(fakeAnalyzeResponse())]);

    Artisan::call('init', ['--update-baseline' => true]);
    $output = Artisan::output();

    expect($output)->toContain('Next steps:')
        ->and($output)->not->toContain('Accept the current images as already handled');
});
