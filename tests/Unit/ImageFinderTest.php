<?php

use App\Support\ImageFinder;

test('finds image files recursively, sorted by pathname', function () {
    createImage('zebra.png');
    createImage('albums/summer/beach.jpg');
    createImage('albums/cover.webp');

    expect((new ImageFinder)->find(workspace()))->toBe([
        workspace().'/albums/cover.webp',
        workspace().'/albums/summer/beach.jpg',
        workspace().'/zebra.png',
    ]);
});

test('filters by image extension, case-insensitively', function () {
    createImage('photo.JPG');
    createImage('scan.jpeg');
    createImage('notes.txt', 'not an image');
    createImage('report.pdf', 'not an image');

    expect((new ImageFinder)->find(workspace()))->toBe([
        workspace().'/photo.JPG',
        workspace().'/scan.jpeg',
    ]);
});

test('skips dot-directories and dot-files', function () {
    createImage('photo.png');
    createImage('.git/logo.png');
    createImage('._photo.jpg');

    expect((new ImageFinder)->find(workspace()))->toBe([workspace().'/photo.png']);
});

test('skips symlinked directories but follows symlinked image files', function () {
    createImage('photo.png');
    $outside = createImage('../outside/linked.png');

    symlink(dirname($outside), workspace().'/storage');
    symlink($outside, workspace().'/alias.png');

    expect((new ImageFinder)->find(workspace()))->toBe([
        workspace().'/alias.png',
        workspace().'/photo.png',
    ]);
});

test('returns an empty list for a directory with no images', function () {
    createImage('readme.md', 'text');

    expect((new ImageFinder)->find(workspace()))->toBe([]);
});

test('excludes files matching .glimpseignore patterns', function () {
    createImage('photo.png');
    createImage('logo.webp');
    createImage('albums/cover.webp');

    file_put_contents(workspace().'/.glimpseignore', "*.webp\n");

    expect((new ImageFinder)->find(workspace()))->toBe([workspace().'/photo.png']);
});

test('prunes directories matching .glimpseignore patterns', function () {
    createImage('photo.png');
    createImage('vendor/package/logo.png');

    file_put_contents(workspace().'/.glimpseignore', "vendor/\n");

    expect((new ImageFinder)->find(workspace()))->toBe([workspace().'/photo.png']);
});

test('negated .glimpseignore patterns re-include files', function () {
    createImage('logo.webp');
    createImage('albums/cover.webp');

    file_put_contents(workspace().'/.glimpseignore', "*.webp\n!albums/cover.webp\n");

    expect((new ImageFinder)->find(workspace()))->toBe([workspace().'/albums/cover.webp']);
});

test('scans normally without a .glimpseignore file', function () {
    createImage('photo.png');
    createImage('albums/cover.webp');

    expect((new ImageFinder)->find(workspace()))->toBe([
        workspace().'/albums/cover.webp',
        workspace().'/photo.png',
    ]);
});
