<?php

use App\Updater\GithubReleasesStrategy;
use Illuminate\Support\Facades\Artisan;

test('the self-update command is only available inside a production build', function () {
    expect(Artisan::all())->not->toHaveKey('self-update');
});

test('the updater downloads the PHAR asset attached to the GitHub release', function () {
    expect(config('updater.strategy'))->toBe(GithubReleasesStrategy::class);
});
