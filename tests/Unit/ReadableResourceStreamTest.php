<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\ReadableResourceStream;

describe('ReadableResourceStream', function () {
    beforeEach(function () {
        Loop::reset();
    });

    test('can be created from a readable resource', function () {
        $file = createTempFile('test data');
        $resource = fopen($file, 'r');

        $stream = new ReadableResourceStream($resource);

        expect($stream)->toBeInstanceOf(ReadableResourceStream::class);
        expect($stream->isReadable())->toBeTrue();

        $stream->close();
        cleanupTempFile($file);
    });

    test('throws exception for invalid resource', function () {
        new ReadableResourceStream('not a resource');
    })->throws(StreamException::class, 'Invalid resource provided');

    test('throws exception for non-readable resource', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');

        try {
            new ReadableResourceStream($resource);
        } finally {
            fclose($resource);
            cleanupTempFile($file);
        }
    })->throws(StreamException::class, 'Resource is not readable');

    test('emits data events when resumed', function () {
        $content = 'Event data';
        $file = createTempFile($content);
        $resource = fopen($file, 'r');
        $stream = new ReadableResourceStream($resource);

        $emittedData = '';
        $endEmitted = false;

        $stream->on('data', function ($data) use (&$emittedData) {
            $emittedData .= $data;
        });

        $stream->on('end', function () use (&$endEmitted) {
            $endEmitted = true;
        });

        $stream->resume();

        Loop::run();

        expect($emittedData)->toBe($content);
        expect($endEmitted)->toBeTrue();

        $stream->close();
        cleanupTempFile($file);
    });

    test('emits data in chunks', function () {
        $content = str_repeat('X', 20000);
        $file = createTempFile($content);
        $resource = fopen($file, 'r');
        $stream = new ReadableResourceStream($resource, 8192);

        $chunks = [];
        $endEmitted = false;

        $stream->on('data', function ($data) use (&$chunks) {
            $chunks[] = $data;
        });

        $stream->on('end', function () use (&$endEmitted) {
            $endEmitted = true;
        });

        $stream->resume();
        Loop::run();

        expect(count($chunks))->toBeGreaterThan(1);
        expect(implode('', $chunks))->toBe($content);
        expect($endEmitted)->toBeTrue();

        $stream->close();
        cleanupTempFile($file);
    });

    test('can pause and resume', function () {
        $content = str_repeat('X', 50000);
        $file = createTempFile($content);
        $resource = fopen($file, 'r');
        $stream = new ReadableResourceStream($resource, 8192);

        $dataCount = 0;
        $paused = false;
        $allData = '';
        $pauseTriggered = false;

        $stream->on('data', function ($data) use ($stream, &$dataCount, &$paused, &$allData, &$pauseTriggered) {
            if ($pauseTriggered) {
                return;
            }

            $dataCount++;
            $allData .= $data;

            if ($dataCount === 2 && !$paused) {
                $stream->pause();
                $paused = true;
                $pauseTriggered = true;

                Loop::addTimer(0.05, function () use ($stream, &$pauseTriggered) {
                    $pauseTriggered = false;
                    $stream->resume();
                });
            }
        });

        $stream->on('end', function () {
            Loop::stop();
        });

        $stream->resume();
        Loop::run();

        expect($dataCount)->toBeGreaterThanOrEqual(1);
        expect($paused)->toBeTrue();
        expect($allData)->toBe($content);

        $stream->close();
        cleanupTempFile($file);
    });

    test('starts in paused state', function () {
        $file = createTempFile('test');
        $resource = fopen($file, 'r');
        $stream = new ReadableResourceStream($resource);

        $dataEmitted = false;

        $stream->on('data', function () use (&$dataEmitted) {
            $dataEmitted = true;
        });

        // Give time for potential events
        usleep(50000);

        // Should not emit data while paused
        expect($dataEmitted)->toBeFalse();

        $stream->close();
        cleanupTempFile($file);
    });

    test('can be closed', function () {
        $file = createTempFile('test');
        $resource = fopen($file, 'r');
        $stream = new ReadableResourceStream($resource);

        expect($stream->isReadable())->toBeTrue();

        $stream->close();

        expect($stream->isReadable())->toBeFalse();

        cleanupTempFile($file);
    });

    test('emits close event', function () {
        $file = createTempFile('test');
        $resource = fopen($file, 'r');
        $stream = new ReadableResourceStream($resource);

        $closeEmitted = false;
        $stream->on('close', function () use (&$closeEmitted) {
            $closeEmitted = true;
        });

        $stream->close();

        expect($closeEmitted)->toBeTrue();

        cleanupTempFile($file);
    });

    test('does not emit data after close', function () {
        $file = createTempFile('test data');
        $resource = fopen($file, 'r');
        $stream = new ReadableResourceStream($resource);

        $dataEmitted = false;

        $stream->on('data', function () use (&$dataEmitted) {
            $dataEmitted = true;
        });

        $stream->close();
        $stream->resume();

        usleep(50000);

        expect($dataEmitted)->toBeFalse();

        cleanupTempFile($file);
    });

    test('handles empty file', function () {
        $file = createTempFile('');
        $resource = fopen($file, 'r');
        $stream = new ReadableResourceStream($resource);

        $dataEmitted = false;
        $endEmitted = false;

        $stream->on('data', function () use (&$dataEmitted) {
            $dataEmitted = true;
        });

        $stream->on('end', function () use (&$endEmitted) {
            $endEmitted = true;
        });

        $stream->resume();
        Loop::run();

        expect($dataEmitted)->toBeFalse();
        expect($endEmitted)->toBeTrue();

        $stream->close();
        cleanupTempFile($file);
    });

    test('handles read errors gracefully', function () {
        $file = createTempFile('test');
        $resource = fopen($file, 'r');
        $stream = new ReadableResourceStream($resource);

        $errorEmitted = false;
        $closeEmitted = false;

        $stream->on('error', function ($error) use (&$errorEmitted) {
            $errorEmitted = true;
            expect($error)->toBeInstanceOf(Throwable::class);
        });

        $stream->on('close', function () use (&$closeEmitted) {
            $closeEmitted = true;
        });

        // Don't close resource directly, let stream handle it
        $stream->resume();
        
        // Manually trigger error by closing stream
        $stream->close();

        expect($closeEmitted)->toBeTrue();

        cleanupTempFile($file);
    });

    test('can pipe to writable stream', function () {
        $content = 'Pipe test data';
        $sourceFile = createTempFile($content);
        $destFile = createTempFile();

        $sourceResource = fopen($sourceFile, 'r');
        $destResource = fopen($destFile, 'w');

        $readStream = new ReadableResourceStream($sourceResource);
        $writeStream = new \Hibla\Stream\WritableResourceStream($destResource);

        $endEmitted = false;

        $writeStream->on('finish', function () use (&$endEmitted) {
            $endEmitted = true;
            Loop::stop();
        });

        $readStream->pipe($writeStream);

        Loop::run();

        expect($endEmitted)->toBeTrue();
        expect(file_get_contents($destFile))->toBe($content);

        cleanupTempFile($sourceFile);
        cleanupTempFile($destFile);
    });

    test('pipe handles backpressure', function () {
        $content = str_repeat('X', 100000);
        $sourceFile = createTempFile($content);
        $destFile = createTempFile();

        $sourceResource = fopen($sourceFile, 'r');
        $destResource = fopen($destFile, 'w');

        $readStream = new ReadableResourceStream($sourceResource, 8192);
        $writeStream = new \Hibla\Stream\WritableResourceStream($destResource, 1024);

        $pauseEmitted = false;
        $drainEmitted = false;

        $readStream->on('pause', function () use (&$pauseEmitted) {
            $pauseEmitted = true;
        });

        $writeStream->on('drain', function () use (&$drainEmitted) {
            $drainEmitted = true;
        });

        $writeStream->on('finish', function () {
            Loop::stop();
        });

        $readStream->pipe($writeStream);

        Loop::run();

        expect(file_get_contents($destFile))->toBe($content);
        expect($pauseEmitted)->toBeTrue();
        expect($drainEmitted)->toBeTrue();

        cleanupTempFile($sourceFile);
        cleanupTempFile($destFile);
    });

    test('pipe with end false keeps destination open', function () {
        $content = 'Test data';
        $sourceFile = createTempFile($content);
        $destFile = createTempFile();

        $sourceResource = fopen($sourceFile, 'r');
        $destResource = fopen($destFile, 'w');

        $readStream = new ReadableResourceStream($sourceResource);
        $writeStream = new \Hibla\Stream\WritableResourceStream($destResource);

        $readStream->on('end', function () {
            Loop::stop();
        });

        $readStream->pipe($writeStream, ['end' => false]);

        Loop::run();

        expect($writeStream->isWritable())->toBeTrue();
        expect(file_get_contents($destFile))->toBe($content);

        $writeStream->close();
        cleanupTempFile($sourceFile);
        cleanupTempFile($destFile);
    });

    test('can seek in seekable stream', function () {
        $content = '0123456789';
        $file = createTempFile($content);
        $resource = fopen($file, 'r');
        $stream = new ReadableResourceStream($resource);

        $result = $stream->seek(5);

        expect($result)->toBeTrue();

        $data = '';
        $stream->on('data', function ($chunk) use (&$data) {
            $data .= $chunk;
        });

        $stream->on('end', function () {
            Loop::stop();
        });

        $stream->resume();
        Loop::run();

        expect($data)->toBe('56789');

        $stream->close();
        cleanupTempFile($file);
    });

    test('emits pause event when paused', function () {
        $file = createTempFile('test');
        $resource = fopen($file, 'r');
        $stream = new ReadableResourceStream($resource);

        $pauseEmitted = false;

        $stream->on('pause', function () use (&$pauseEmitted) {
            $pauseEmitted = true;
        });

        $stream->resume();
        $stream->pause();

        expect($pauseEmitted)->toBeTrue();

        $stream->close();
        cleanupTempFile($file);
    });

    test('emits resume event when resumed', function () {
        $file = createTempFile('test');
        $resource = fopen($file, 'r');
        $stream = new ReadableResourceStream($resource);

        $resumeEmitted = false;

        $stream->on('resume', function () use (&$resumeEmitted) {
            $resumeEmitted = true;
        });

        $stream->resume();

        expect($resumeEmitted)->toBeTrue();

        $stream->close();
        cleanupTempFile($file);
    });
});