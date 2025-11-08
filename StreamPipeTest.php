<?php

use Hibla\Stream\Stream;

describe('Stream Piping', function () {
    test('can pipe from readable to writable stream', function () {
        $sourceContent = 'Piped content from source to destination';
        $sourceFile = createTempFile($sourceContent);
        $destFile = createTempFile();
        
        $source = Stream::readableFile($sourceFile);
        $dest = Stream::writableFile($destFile);
        
        $bytesTransferred = $source->pipe($dest)->await();
        
        expect($bytesTransferred)->toBe(strlen($sourceContent));
        expect(file_get_contents($destFile))->toBe($sourceContent);
        
        cleanupTempFile($sourceFile);
        cleanupTempFile($destFile);
    });

    test('pipe handles large files efficiently', function () {
        $size = 1024 * 1024; // 1MB
        $sourceContent = str_repeat('X', $size);
        $sourceFile = createTempFile($sourceContent);
        $destFile = createTempFile();
        
        $startMem = memory_get_usage();
        
        $source = Stream::readableFile($sourceFile);
        $dest = Stream::writableFile($destFile);
        
        $bytesTransferred = $source->pipe($dest)->await();
        
        $memUsed = memory_get_usage() - $startMem;
        
        expect($bytesTransferred)->toBe($size);
        expect($memUsed)->toBeLessThan(500 * 1024); // Less than 500KB
        expect(file_get_contents($destFile))->toBe($sourceContent);
        
        cleanupTempFile($sourceFile);
        cleanupTempFile($destFile);
    });

    test('pipe can be configured not to end destination', function () {
        $sourceFile = createTempFile('First part');
        $destFile = createTempFile();
        
        $source = Stream::readableFile($sourceFile);
        $dest = Stream::writableFile($destFile);
        
        $source->pipe($dest, ['end' => false])->await();
        
        expect($dest->isWritable())->toBeTrue();
        
        $dest->write(' Second part')->await();
        $dest->end()->await();
        
        expect(file_get_contents($destFile))->toBe('First part Second part');
        
        cleanupTempFile($sourceFile);
        cleanupTempFile($destFile);
    });

    test('pipe can be cancelled', function () {
        $sourceContent = str_repeat('X', 1024 * 1024); // 1MB
        $sourceFile = createTempFile($sourceContent);
        $destFile = createTempFile();
        
        $source = Stream::readableFile($sourceFile);
        $dest = Stream::writableFile($destFile);
        
        $promise = $source->pipe($dest);
        
        // Cancel immediately
        $promise->cancel();
        
        expect($promise->isCancelled())->toBeTrue();
        expect($source->isPaused())->toBeTrue();
        
        $source->close();
        $dest->close();
        
        cleanupTempFile($sourceFile);
        cleanupTempFile($destFile);
    });
});