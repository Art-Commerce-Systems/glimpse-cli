<?php

use MathiasGrimm\GlimpseCli\Support\Paths;

test('root is the current working directory with normalized separators', function () {
    chdirWorkspace();

    expect(Paths::root())->toBe(str_replace('\\', '/', (string) realpath(workspace())));
});

test('contains accepts only paths strictly inside the directory', function () {
    expect(Paths::contains('/scan/root', '/scan/root/a.png'))->toBeTrue()
        ->and(Paths::contains('/scan/root/', '/scan/root/sub/a.png'))->toBeTrue()
        ->and(Paths::contains('C:\\scan\\root', 'C:\\scan\\root\\a.png'))->toBeTrue()
        ->and(Paths::contains('/scan/root', '/scan/root'))->toBeFalse()
        ->and(Paths::contains('/scan/root', '/scan/rootbeer/a.png'))->toBeFalse()
        ->and(Paths::contains('/scan/root', '/elsewhere/a.png'))->toBeFalse()
        ->and(Paths::contains('', '/a.png'))->toBeFalse();
});

test('relativePath strips the directory prefix and normalizes separators', function () {
    expect(Paths::relativePath('/scan/root', '/scan/root/sub/a.png'))->toBe('sub/a.png')
        ->and(Paths::relativePath('/scan/root/', '/scan/root/a.png'))->toBe('a.png')
        ->and(Paths::relativePath('C:\\scan\\root', 'C:\\scan\\root\\sub\\a.png'))->toBe('sub/a.png');
});

test('relativePath rejects a path outside the directory instead of mangling a key', function (string $path) {
    Paths::relativePath('/scan/root', $path);
})->throws(InvalidArgumentException::class, 'is not inside')->with([
    'unrelated path' => '/elsewhere/a.png',
    'sibling sharing a prefix' => '/scan/rootbeer/a.png',
    'the directory itself' => '/scan/root',
]);

test('keyPrefix maps scan directories to root-relative key prefixes', function () {
    createImage('sub/photo.png');
    chdirWorkspace();

    expect(Paths::keyPrefix(Paths::root(), workspace()))->toBe('')
        ->and(Paths::keyPrefix(Paths::root(), workspace().'/sub'))->toBe('sub/')
        ->and(Paths::keyPrefix(Paths::root(), '/tmp'))->toBeNull();
});
