<?php

use App\Support\ScaffoldFile;
use GlimpseImg\ApiException;

function scaffoldRoot(): string
{
    $root = workspace().'/scaffold';

    if (! is_dir($root)) {
        mkdir($root, 0755, true);
    }

    return $root;
}

test('creates a new file with the exact content, building parent directories', function () {
    $root = scaffoldRoot();

    ScaffoldFile::write($root, 'a/b/file.txt', "content\n");

    expect((string) file_get_contents($root.'/a/b/file.txt'))->toBe("content\n");
});

test('creation is exclusive, an existing file is never truncated', function () {
    $root = scaffoldRoot();
    file_put_contents($root.'/file.txt', "previous\n");

    expect(fn () => ScaffoldFile::write($root, 'file.txt', "next\n"))
        ->toThrow(ApiException::class)
        ->and((string) file_get_contents($root.'/file.txt'))->toBe("previous\n");
});

test('replacement swaps the full content in atomically', function () {
    $root = scaffoldRoot();
    file_put_contents($root.'/file.txt', "previous\n");

    ScaffoldFile::write($root, 'file.txt', "next\n", replace: true);

    expect((string) file_get_contents($root.'/file.txt'))->toBe("next\n")
        ->and(glob($root.'/.file.txt.*'))->toBe([]);
});

test('a failed replacement leaves the previous file untouched', function () {
    $root = scaffoldRoot();
    mkdir($root.'/locked');
    file_put_contents($root.'/locked/file.txt', "previous\n");

    // A read-only directory makes the private temporary file impossible
    // to create, so the swap must fail before the previous file is
    // touched.
    chmod($root.'/locked', 0555);

    try {
        expect(fn () => ScaffoldFile::write($root, 'locked/file.txt', "next\n", replace: true))
            ->toThrow(ApiException::class);
    } finally {
        chmod($root.'/locked', 0755);
    }

    expect((string) file_get_contents($root.'/locked/file.txt'))->toBe("previous\n");
});

test('a symlinked directory below the root is refused', function () {
    $root = scaffoldRoot();
    mkdir($root.'/real');
    symlink($root.'/real', $root.'/linked');

    expect(fn () => ScaffoldFile::write($root, 'linked/file.txt', "content\n"))
        ->toThrow(ApiException::class, 'is a symbolic link')
        ->and(glob($root.'/real/*'))->toBe([]);
});

test('a symlinked target file is refused and what it points at preserved', function () {
    $root = scaffoldRoot();
    file_put_contents($root.'/target.txt', "original\n");
    symlink($root.'/target.txt', $root.'/link.txt');

    expect(fn () => ScaffoldFile::write($root, 'link.txt', "next\n", replace: true))
        ->toThrow(ApiException::class, 'is a symbolic link')
        ->and((string) file_get_contents($root.'/target.txt'))->toBe("original\n");
});
