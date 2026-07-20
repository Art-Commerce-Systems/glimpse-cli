<?php

use App\Glimpse\Config;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * The full /v1/usage response body, mirroring the real API shape
 * (see UsageSummaryResource on the server).
 *
 * @return array<string, mixed>
 */
function fullUsageApiResponse(): array
{
    return [
        'period' => [
            'from' => '2026-07-01T00:00:00+00:00',
            'to' => '2026-07-31T23:59:59+00:00',
        ],
        'operations' => 68,
        'bytes_saved' => 62111321,
        'average_reduction' => 45,
        'by_operation' => [
            'convert' => 22,
            'optimize' => 18,
            'resize' => 7,
            'thumbnail' => 8,
            'info' => 6,
            'analyze' => 7,
        ],
    ];
}

beforeEach(function () {
    putenv('GLIMPSE_TOKEN=test-token');

    Http::fake(['*/v1/usage' => Http::response(['data' => fullUsageApiResponse()])]);
});

test('prints a pretty usage table by default', function () {
    $this->artisan('usage')
        ->expectsOutputToContain('2026-07-01 to 2026-07-31')
        ->expectsOutputToContain('59.2 MB')
        ->expectsOutputToContain('45%')
        ->expectsOutputToContain('convert: 22')
        ->expectsOutputToContain('analyze: 7')
        ->assertExitCode(0);
});

test('the --json output mirrors the API response byte for byte', function () {
    $exitCode = Artisan::call('usage', ['--json' => true]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toBe(json_encode(fullUsageApiResponse(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
});

test('fails cleanly without a token', function () {
    putenv('GLIMPSE_TOKEN');

    $this->artisan('usage')
        ->expectsOutputToContain('Not authenticated. Run: glimpse auth')
        ->assertExitCode(1);
});

test('refuses the built-in public token without calling the API', function () {
    putenv('GLIMPSE_TOKEN');
    app()->instance(Config::class, new Config(publicTokenOverride: 'pub-token'));
    Http::fake();

    $exitCode = Artisan::call('usage');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('The built-in public CI token only runs check and analyze.');

    Http::assertNothingSent();
});
