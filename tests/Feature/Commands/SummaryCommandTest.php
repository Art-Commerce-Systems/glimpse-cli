<?php

use Illuminate\Support\Facades\Artisan;

const EYE_FRAGMENT = '▄▄▄██▀▀▀██▀▀';
const WORDMARK_FRAGMENT = '██   ███ ██      ██';
const TAGLINE = 'The image API for developers.';

afterEach(function () {
    putenv('COLUMNS');
});

test('shows the eye and wordmark banner on wide terminals', function () {
    putenv('COLUMNS=120');

    Artisan::call('summary');
    $output = Artisan::output();

    expect($output)
        ->toContain(EYE_FRAGMENT)
        ->toContain(WORDMARK_FRAGMENT)
        ->toContain(TAGLINE)
        ->toContain('by Mathias Grimm')
        ->toContain('USAGE:')
        ->toContain('convert');
});

test('drops the eye but keeps the wordmark on 80-column terminals', function () {
    putenv('COLUMNS=80');

    Artisan::call('summary');
    $output = Artisan::output();

    expect($output)
        ->toContain(WORDMARK_FRAGMENT)
        ->toContain(TAGLINE)
        ->not->toContain(EYE_FRAGMENT);
});

test('falls back to a compact banner on narrow terminals', function () {
    putenv('COLUMNS=40');

    Artisan::call('summary');
    $output = Artisan::output();

    expect($output)
        ->toContain('● glimpse')
        ->toContain(TAGLINE)
        ->not->toContain(WORDMARK_FRAGMENT)
        ->not->toContain(EYE_FRAGMENT);
});

test('does not print the title line twice', function () {
    putenv('COLUMNS=120');

    Artisan::call('summary');

    // The vendor title renders as "Glimpse  <version>" with a double space;
    // the banner carries name and version itself, so it must not appear.
    expect(Artisan::output())->not->toContain('Glimpse  ');
});

test('leaves the list command untouched', function () {
    putenv('COLUMNS=120');

    Artisan::call('list');
    $output = Artisan::output();

    expect($output)
        ->toContain('Glimpse  ')
        ->toContain('USAGE:')
        ->not->toContain(TAGLINE)
        ->not->toContain(WORDMARK_FRAGMENT);
});

test('keeps json output clean of the banner', function () {
    Artisan::call('summary', ['--format' => 'json']);

    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toBeArray()->toHaveKey('commands');
});
