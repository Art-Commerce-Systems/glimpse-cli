<?php

use App\Support\IgnoreFile;

function ignoreFile(string $content): IgnoreFile
{
    mkdir(workspace(), 0755, true);
    file_put_contents(workspace().'/.glimpseignore', $content);

    return IgnoreFile::load(workspace());
}

test('matches files by glob pattern', function () {
    $ignore = ignoreFile("*.png\n");

    expect($ignore->ignores('logo.png'))->toBeTrue()
        ->and($ignore->ignores('nested/logo.png'))->toBeTrue()
        ->and($ignore->ignores('logo.jpg'))->toBeFalse();
});

test('matches everything under a double-star pattern', function () {
    $ignore = ignoreFile("docs/**\n");

    expect($ignore->ignores('docs/shot.png'))->toBeTrue()
        ->and($ignore->ignores('docs/deep/shot.png'))->toBeTrue()
        ->and($ignore->ignores('src/shot.png'))->toBeFalse();
});

test('matches directories with a trailing-slash pattern', function () {
    $ignore = ignoreFile("vendor/\n");

    expect($ignore->ignores('vendor', isDirectory: true))->toBeTrue()
        ->and($ignore->ignores('vendor/logo.png'))->toBeTrue()
        ->and($ignore->ignores('vendor.png'))->toBeFalse();
});

test('negated patterns re-include files', function () {
    $ignore = ignoreFile("*.png\n!keep.png\n");

    expect($ignore->ignores('logo.png'))->toBeTrue()
        ->and($ignore->ignores('keep.png'))->toBeFalse();
});

test('skips comments and blank lines', function () {
    $ignore = ignoreFile("# ignore the logo\n\n*.png\n");

    expect($ignore->ignores('logo.png'))->toBeTrue()
        ->and($ignore->ignores('# ignore the logo'))->toBeFalse();
});

test('ignores nothing when the file is missing or empty', function () {
    expect(IgnoreFile::load(workspace())->ignores('logo.png'))->toBeFalse()
        ->and(ignoreFile('')->ignores('logo.png'))->toBeFalse();
});

test('normalizes backslashes before matching', function () {
    $ignore = ignoreFile("docs/**\n");

    expect($ignore->ignores('docs\\shot.png'))->toBeTrue();
});
