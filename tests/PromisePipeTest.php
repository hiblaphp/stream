<?php

declare(strict_types=1);

use Hibla\Stream\PromiseReadableStream;
use Hibla\Stream\PromiseWritableStream;

test('pipes readable to writable stream', function () {
    $source = createTempFile('Pipe test content');
    $dest = createTempFile();

    $rsrc = fopen($source, 'r');
    $wsrc = fopen($dest, 'w');
    stream_set_blocking($wsrc, false);

    $readable = PromiseReadableStream::fromResource($rsrc);
    $writable = PromiseWritableStream::fromResource($wsrc);

    $bytes = $readable->pipeAsync($writable)->wait();
    $result = file_get_contents($dest);

    cleanupTempFile($source);
    cleanupTempFile($dest);

    expect($result)->toBe('Pipe test content')
        ->and($bytes)->toBe(17)
    ;
});
