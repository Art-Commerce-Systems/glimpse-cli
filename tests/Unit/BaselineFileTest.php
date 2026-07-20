<?php

use MathiasGrimm\GlimpseCli\Support\BaselineFile;
use MathiasGrimm\GlimpsePhp\ApiException;

test('a missing or empty file is an empty baseline', function () {
    $path = createImage('photo.png');

    expect(BaselineFile::load(workspace())->skips('photo.png', $path))->toBeFalse()
        ->and(BaselineFile::load(workspace())->count())->toBe(0);

    file_put_contents(baselinePath(), '');

    expect(BaselineFile::load(workspace())->skips('photo.png', $path))->toBeFalse();
});

test('skips a file whose path, size, and hash all match', function () {
    $path = createImage('photos/a.png');
    writeBaseline(['photos/a.png' => baselineEntry($path)]);

    expect(BaselineFile::load(workspace())->skips('photos/a.png', $path))->toBeTrue();
});

test('does not skip when the size differs', function () {
    $path = createImage('photo.png');
    $entry = baselineEntry($path);
    $entry['size'] += 1;

    writeBaseline(['photo.png' => $entry]);

    expect(BaselineFile::load(workspace())->skips('photo.png', $path))->toBeFalse();
});

test('does not skip when the size matches but the content changed', function () {
    $path = createImage('photo.png');
    $entry = baselineEntry($path);

    file_put_contents($path, str_repeat('x', $entry['size']));
    writeBaseline(['photo.png' => $entry]);

    expect(BaselineFile::load(workspace())->skips('photo.png', $path))->toBeFalse();
});

test('does not skip an unknown path', function () {
    $known = createImage('known.png');
    $other = createImage('other.png');

    writeBaseline(['known.png' => baselineEntry($known)]);

    expect(BaselineFile::load(workspace())->skips('other.png', $other))->toBeFalse();
});

test('record adds a new entry and refreshes a stale one', function () {
    $path = createImage('photo.png');

    $baseline = BaselineFile::load(workspace());
    $baseline->record('photo.png', $path, 'analyze');

    expect($baseline->count())->toBe(1)
        ->and($baseline->skips('photo.png', $path))->toBeTrue();

    file_put_contents($path, 'different content entirely');

    expect($baseline->skips('photo.png', $path))->toBeFalse();

    $baseline->record('photo.png', $path, 'analyze');

    expect($baseline->count())->toBe(1)
        ->and($baseline->skips('photo.png', $path))->toBeTrue();
});

test('prune drops entries for deleted files and keeps live ones', function () {
    $kept = createImage('kept.png');
    $gone = createImage('gone.png');

    $baseline = BaselineFile::load(workspace());
    $baseline->record('kept.png', $kept, 'analyze');
    $baseline->record('gone.png', $gone, 'analyze');

    unlink($gone);
    $baseline->prune(workspace());

    expect($baseline->count())->toBe(1)
        ->and($baseline->skips('kept.png', $kept))->toBeTrue();
});

test('save writes pretty json with sorted keys and a trailing newline', function () {
    $b = createImage('b.png');
    $a = createImage('a.png');

    $baseline = BaselineFile::load(workspace());
    $baseline->record('b.png', $b, 'analyze');
    $baseline->record('a.png', $a, 'convert');
    $baseline->save(workspace());

    $expected = json_encode([
        '_readme' => BaselineFile::README,
        'files' => [
            'a.png' => baselineEntry($a, 'convert'),
            'b.png' => baselineEntry($b, 'analyze'),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    expect(file_get_contents(baselinePath()))->toBe($expected.PHP_EOL);
});

test('an empty baseline saves as an empty files object under the readme header', function () {
    mkdir(workspace(), 0755, true);

    BaselineFile::load(workspace())->save(workspace());

    $expected = json_encode([
        '_readme' => BaselineFile::README,
        'files' => new stdClass,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    expect(file_get_contents(baselinePath()))->toBe($expected.PHP_EOL);
});

test('a saved baseline loads back with the same matching', function () {
    $path = createImage('photos/a.png');

    $baseline = BaselineFile::load(workspace());
    $baseline->record('photos/a.png', $path, 'analyze');
    $baseline->save(workspace());

    expect(BaselineFile::load(workspace())->skips('photos/a.png', $path))->toBeTrue();
});

test('load fails loudly on an unreadable baseline', function () {
    mkdir(workspace(), 0755, true);
    $path = baselinePath();
    file_put_contents($path, '{"files": {}}');
    chmod($path, 0000);

    try {
        expect(fn () => BaselineFile::load(workspace()))->toThrow(ApiException::class, 'Could not read');
    } finally {
        chmod($path, 0644);
    }
});

test('save fails loudly instead of writing garbage for a non-utf8 filename', function () {
    $path = createImage('photo.png');

    $baseline = BaselineFile::load(workspace());
    $baseline->record('photo.png', $path, 'analyze');
    $baseline->put("caf\xE9.png", 1, 'abc', 'analyze');

    expect(fn () => $baseline->save(workspace()))->toThrow(ApiException::class, 'Could not encode')
        ->and(file_exists(baselinePath()))->toBeFalse();
});

test('save fails loudly when the directory is not writable', function () {
    $path = createImage('photo.png');

    $baseline = BaselineFile::load(workspace());
    $baseline->record('photo.png', $path, 'analyze');

    chmod(workspace(), 0555);

    try {
        expect(fn () => $baseline->save(workspace()))->toThrow(ApiException::class, 'Could not write');
    } finally {
        chmod(workspace(), 0755);
    }
});

test('loading for update fails fast when another process holds the lock', function () {
    $path = createImage('photo.png');
    writeBaseline(['photo.png' => baselineEntry($path)]);

    $other = fopen(baselinePath(), 'r+');
    flock($other, LOCK_EX);

    try {
        expect(fn () => BaselineFile::load(workspace(), forUpdate: true))
            ->toThrow(ApiException::class, 'locked by another glimpse process');
    } finally {
        flock($other, LOCK_UN);
        fclose($other);
    }
});

test('loading for update takes the lock first and holds it until save releases it', function () {
    writeBaseline();

    $baseline = BaselineFile::load(workspace(), forUpdate: true);
    $other = fopen(baselinePath(), 'r+');

    expect(flock($other, LOCK_EX | LOCK_NB))->toBeFalse();

    $baseline->record('photo.png', createImage('photo.png'), 'analyze');
    $baseline->save(workspace());

    expect(flock($other, LOCK_EX | LOCK_NB))->toBeTrue()
        ->and(array_keys(baselineFiles()))->toBe(['photo.png']);

    flock($other, LOCK_UN);
    fclose($other);
});

test('a plain load does not lock the file', function () {
    $path = createImage('photo.png');
    writeBaseline(['photo.png' => baselineEntry($path)]);

    BaselineFile::load(workspace());

    $other = fopen(baselinePath(), 'r+');

    expect(flock($other, LOCK_EX | LOCK_NB))->toBeTrue();

    flock($other, LOCK_UN);
    fclose($other);
});

test('a failed save releases the lock taken at load', function () {
    writeBaseline();

    $baseline = BaselineFile::load(workspace(), forUpdate: true);
    $baseline->put("caf\xE9.png", 1, 'abc', 'analyze');

    expect(fn () => $baseline->save(workspace()))->toThrow(ApiException::class, 'Could not encode');

    $other = fopen(baselinePath(), 'r+');

    expect(flock($other, LOCK_EX | LOCK_NB))->toBeTrue();

    flock($other, LOCK_UN);
    fclose($other);
});

test('save fails fast when another process created the baseline since load', function () {
    mkdir(workspace(), 0755, true);

    $baseline = BaselineFile::load(workspace());
    $baseline->put('a.png', 1, 'abc', 'analyze');

    writeBaseline();

    expect(fn () => $baseline->save(workspace()))
        ->toThrow(ApiException::class, 'created by another glimpse process');
});

test('concurrent first-time creation fails loudly instead of losing entries', function () {
    $a = createImage('a.png');
    $b = createImage('b.png');

    $first = BaselineFile::load(workspace(), forUpdate: true);
    $second = BaselineFile::load(workspace(), forUpdate: true);

    $first->record('a.png', $a, 'analyze');
    $first->save(workspace());

    $second->record('b.png', $b, 'analyze');

    expect(fn () => $second->save(workspace()))
        ->toThrow(ApiException::class, 'created by another glimpse process')
        ->and(array_keys(baselineFiles()))->toBe(['a.png']);
});

test('save leaves no temporary or stray files behind', function () {
    writeBaseline();

    $baseline = BaselineFile::load(workspace(), forUpdate: true);
    $baseline->record('photo.png', createImage('photo.png'), 'analyze');
    $baseline->save(workspace());

    expect(glob(baselinePath().'.*'))->toBe([])
        ->and(array_keys(baselineFiles()))->toBe(['photo.png']);
});

test('record skips a file that vanished instead of crashing', function () {
    $path = createImage('photo.png');
    unlink($path);

    $baseline = BaselineFile::load(workspace());
    $baseline->record('photo.png', $path, 'analyze');

    expect($baseline->count())->toBe(0);
});

test('put stores a precomputed entry and forget removes one', function () {
    $path = createImage('photo.png');

    $baseline = BaselineFile::load(workspace());
    $baseline->put('photo.png', (int) filesize($path), (string) hash_file('xxh128', $path), 'analyze');

    expect($baseline->skips('photo.png', $path))->toBeTrue();

    $baseline->forget('photo.png');

    expect($baseline->count())->toBe(0)
        ->and($baseline->skips('photo.png', $path))->toBeFalse();
});

test('load fails loudly on a malformed baseline', function (string $content) {
    mkdir(workspace(), 0755, true);
    file_put_contents(baselinePath(), $content);

    BaselineFile::load(workspace());
})->throws(ApiException::class, 'Malformed')->with([
    'invalid json' => '{nope',
    'not an object' => '[1, 2]',
    'missing files key' => '{"paths": {}}',
    'entry missing xxh128' => '{"files": {"a.png": {"size": 70, "via": "analyze"}}}',
    'entry missing via' => '{"files": {"a.png": {"size": 70, "xxh128": "abc"}}}',
    'entry with non-integer size' => '{"files": {"a.png": {"size": "70", "xxh128": "abc", "via": "analyze"}}}',
]);
