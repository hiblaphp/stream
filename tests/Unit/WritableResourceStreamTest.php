<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\WritableResourceStream;

describe('WritableResourceStream', function () {
    beforeEach(function () {
        Loop::reset();
    });

    test('can be created from a writable resource', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');

        $stream = new WritableResourceStream($resource);

        expect($stream)->toBeInstanceOf(WritableResourceStream::class);
        expect($stream->isWritable())->toBeTrue();

        $stream->close();
        cleanupTempFile($file);
    });

    test('throws exception for invalid resource', function () {
        new WritableResourceStream('not a resource');
    })->throws(StreamException::class, 'Invalid resource provided');

    test('throws exception for non-writable resource', function () {
        $file = createTempFile('test');
        $resource = fopen($file, 'r');

        try {
            new WritableResourceStream($resource);
        } finally {
            fclose($resource);
            cleanupTempFile($file);
        }
    })->throws(StreamException::class, 'Resource is not writable');

    test('can write data', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableResourceStream($resource);

        $content = 'Hello, World!';
        $result = $stream->write($content);

        expect($result)->toBeTrue();

        Loop::run();
        $stream->close();

        expect(file_get_contents($file))->toBe($content);

        cleanupTempFile($file);
    });

    test('can write multiple chunks', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableResourceStream($resource);

        $chunks = ['First ', 'Second ', 'Third'];

        foreach ($chunks as $chunk) {
            $stream->write($chunk);
        }

        Loop::run();
        $stream->close();

        expect(file_get_contents($file))->toBe(implode('', $chunks));

        cleanupTempFile($file);
    });

    test('can end stream with data', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableResourceStream($resource);

        $finishEmitted = false;
        $stream->on('finish', function () use (&$finishEmitted) {
            $finishEmitted = true;
        });

        $stream->end('Final data');

        Loop::run();

        expect($finishEmitted)->toBeTrue();
        expect($stream->isWritable())->toBeFalse();
        expect(file_get_contents($file))->toBe('Final data');

        cleanupTempFile($file);
    });

    test('can end stream without data', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableResourceStream($resource);

        $stream->write('Some data');
        $stream->end();

        Loop::run();

        expect($stream->isWritable())->toBeFalse();
        expect(file_get_contents($file))->toBe('Some data');

        cleanupTempFile($file);
    });

    test('write returns true for empty string', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableResourceStream($resource);

        $result = $stream->write('');

        expect($result)->toBeTrue();

        $stream->close();
        cleanupTempFile($file);
    });

    test('emits drain event when buffer drains', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableResourceStream($resource, 1024); // Small buffer

        $drainEmitted = false;
        $stream->on('drain', function () use (&$drainEmitted) {
            $drainEmitted = true;
        });

        // Write data larger than buffer to trigger drain
        $largeData = str_repeat('X', 5000);
        $stream->write($largeData);

        Loop::run();

        expect($drainEmitted)->toBeTrue();

        $stream->close();
        cleanupTempFile($file);
    });

    test('write returns false when buffer is full', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableResourceStream($resource, 100); // Very small buffer

        $largeData = str_repeat('X', 1000);
        $result = $stream->write($largeData);

        // Should return false indicating buffer is full
        expect($result)->toBeFalse();

        $stream->close();
        cleanupTempFile($file);
    });

    test('can be closed', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableResourceStream($resource);

        expect($stream->isWritable())->toBeTrue();

        $stream->close();

        expect($stream->isWritable())->toBeFalse();

        cleanupTempFile($file);
    });

    test('emits close event', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableResourceStream($resource);

        $closeEmitted = false;
        $stream->on('close', function () use (&$closeEmitted) {
            $closeEmitted = true;
        });

        $stream->close();

        expect($closeEmitted)->toBeTrue();

        cleanupTempFile($file);
    });

    test('write returns false after close', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableResourceStream($resource);

        $stream->close();

        $result = $stream->write('test');

        expect($result)->toBeFalse();

        cleanupTempFile($file);
    });

    test('does not emit error event on write after close', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableResourceStream($resource);

        $errorEmitted = false;
        $stream->on('error', function ($error) use (&$errorEmitted) {
            $errorEmitted = true;
        });

        $stream->close();
        $result = $stream->write('test');

        expect($result)->toBeFalse();
        expect($errorEmitted)->toBeFalse(); // Should just return false, not emit error

        cleanupTempFile($file);
    });

    test('handles large data efficiently', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableResourceStream($resource);

        $largeData = str_repeat('X', 1024 * 1024); // 1MB

        $stream->write($largeData);
        
        Loop::run();
        $stream->close();

        expect(filesize($file))->toBe(strlen($largeData));

        cleanupTempFile($file);
    });

    test('cannot write after ending', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableResourceStream($resource);

        $stream->end();

        $result = $stream->write('more data');

        expect($result)->toBeFalse();

        cleanupTempFile($file);
    });

    test('multiple writes are buffered', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableResourceStream($resource);

        $stream->write('first');
        $stream->write('second');
        $stream->write('third');

        Loop::run();
        $stream->close();

        expect(file_get_contents($file))->toBe('firstsecondthird');

        cleanupTempFile($file);
    });

    test('end flushes remaining buffer', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableResourceStream($resource);

        $stream->write('buffered data');
        $stream->end();

        Loop::run();

        expect(file_get_contents($file))->toBe('buffered data');

        cleanupTempFile($file);
    });

    test('emits pipe event when piped to', function () {
        $sourceFile = createTempFile('test data');
        $destFile = createTempFile();

        $sourceResource = fopen($sourceFile, 'r');
        $destResource = fopen($destFile, 'w');

        $readStream = new \Hibla\Stream\ReadableResourceStream($sourceResource);
        $writeStream = new WritableResourceStream($destResource);

        $pipeEmitted = false;
        $writeStream->on('pipe', function ($source) use (&$pipeEmitted, $readStream) {
            $pipeEmitted = true;
            expect($source)->toBe($readStream);
        });

        $readStream->pipe($writeStream);

        expect($pipeEmitted)->toBeTrue();

        Loop::run();

        cleanupTempFile($sourceFile);
        cleanupTempFile($destFile);
    });
});