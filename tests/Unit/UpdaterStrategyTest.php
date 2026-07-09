<?php

use App\Updater\GithubReleasesStrategy;
use Humbug\SelfUpdate\Strategy\GithubStrategy as HumbugGithubStrategy;

test('an explicitly set phar name is preserved', function () {
    $strategy = new GithubReleasesStrategy;
    $strategy->setPharName('custom');

    expect($strategy->getPharName())->toBe('custom');
});

test('the download url points at the release asset named after the phar', function () {
    $strategy = new GithubReleasesStrategy;
    $strategy->setPharName('glimpse');

    (new ReflectionProperty(HumbugGithubStrategy::class, 'remoteVersion'))->setValue($strategy, 'v0.2.0');

    $url = (new ReflectionMethod($strategy, 'getDownloadUrl'))->invoke($strategy, [
        'source' => ['url' => 'https://github.com/mathiasgrimm/glimpse-cli.git'],
    ]);

    expect($url)->toBe('https://github.com/mathiasgrimm/glimpse-cli/releases/download/v0.2.0/glimpse');
});
