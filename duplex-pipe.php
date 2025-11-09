<?php

require_once __DIR__ . '/vendor/autoload.php';

use Hibla\Stream\DuplexStream;
use Hibla\Stream\WritableStream;
use Hibla\EventLoop\Loop;

echo "=== DuplexStream Piping Test ===\n\n";

// Test 1: Pipe DuplexStream to WritableStream
echo "Test 1: Pipe DuplexStream (source) to WritableStream (destination)\n";
echo "========================================\n\n";

// Create source duplex stream
$sourcePath = sys_get_temp_dir() . '/pipe_source_' . uniqid() . '.txt';
$sourceResource = fopen($sourcePath, 'w+');
$sourceStream = new DuplexStream($sourceResource);

// Create destination writable stream
$destPath = sys_get_temp_dir() . '/pipe_dest_' . uniqid() . '.txt';
$destResource = fopen($destPath, 'w');
$destStream = new WritableStream($destResource);

// Write some data to source first
$testData = "Line 1\nLine 2\nLine 3\nLine 4\nLine 5\n";

$sourceStream->write($testData)
    ->then(function ($bytes) use ($testData) {
        echo "1.1. Written $bytes bytes to source stream\n";
        echo "     Content: " . json_encode($testData) . "\n\n";
        return $bytes;
    })
    ->then(function () use ($sourceResource, $sourceStream, $destStream) {
        echo "1.2. Seeking source to beginning...\n";
        fseek($sourceResource, 0, SEEK_SET);
        
        echo "1.3. Starting pipe operation...\n";
        return $sourceStream->pipe($destStream, ['end' => true]);
    })
    ->then(function ($totalBytes) use ($destPath) {
        echo "     ✓ Pipe completed! Total bytes transferred: $totalBytes\n\n";
        
        echo "1.4. Verifying destination file content...\n";
        $content = file_get_contents($destPath);
        echo "     Content: " . json_encode($content) . "\n";
        echo "     Length: " . strlen($content) . " bytes\n\n";
        
        return $content;
    })
    ->then(function ($content) use ($testData) {
        if ($content === $testData) {
            echo "     ✓ Content matches! Pipe successful!\n\n";
        } else {
            echo "     ✗ Content mismatch!\n";
            echo "     Expected: " . json_encode($testData) . "\n";
            echo "     Got: " . json_encode($content) . "\n\n";
        }
    })
    ->then(function () use ($sourceStream, $sourcePath, $destPath) {
        echo "1.5. Cleaning up test 1...\n";
        $sourceStream->close();
        @unlink($sourcePath);
        @unlink($destPath);
        echo "     ✓ Cleanup complete\n\n";
    })
    ->then(function () {
        echo "\n" . str_repeat("=", 60) . "\n\n";
        
        // Test 2: Pipe DuplexStream to another DuplexStream
        echo "Test 2: Pipe DuplexStream to another DuplexStream\n";
        echo "========================================\n\n";
        
        // Create two duplex streams
        $source2Path = sys_get_temp_dir() . '/pipe2_source_' . uniqid() . '.txt';
        $source2Resource = fopen($source2Path, 'w+');
        $source2Stream = new DuplexStream($source2Resource);
        
        $dest2Path = sys_get_temp_dir() . '/pipe2_dest_' . uniqid() . '.txt';
        $dest2Resource = fopen($dest2Path, 'w+');
        $dest2Stream = new DuplexStream($dest2Resource);
        
        $test2Data = "First chunk\n" . str_repeat("x", 100) . "\nSecond chunk\n";
        
        // Return context for next chain
        return compact('source2Stream', 'dest2Stream', 'source2Path', 'dest2Path', 'source2Resource', 'test2Data');
    })
    ->then(function ($context) {
        return $context['source2Stream']->write($context['test2Data'])
            ->then(function ($bytes) use ($context) {
                echo "2.1. Written $bytes bytes to source\n";
                echo "     Total content length: " . strlen($context['test2Data']) . "\n\n";
                fseek($context['source2Resource'], 0, SEEK_SET);
                return $context; // Pass context forward
            });
    })
    ->then(function ($context) {
        echo "2.2. Piping from source DuplexStream to destination DuplexStream...\n";
        
        // Listen for data events on destination
        $context['dest2Stream']->on('data', function ($data) {
            echo "     → Destination received: " . strlen($data) . " bytes\n";
        });
        
        return $context['source2Stream']->pipe($context['dest2Stream'], ['end' => true])
            ->then(function ($totalBytes) use ($context) {
                $context['totalBytes'] = $totalBytes;
                return $context;
            });
    })
    ->then(function ($context) {
        echo "     ✓ Pipe completed! Total: {$context['totalBytes']} bytes\n\n";
        
        echo "2.3. Reading back from destination...\n";
        $destContent = file_get_contents($context['dest2Path']);
        echo "     Read: " . strlen($destContent) . " bytes\n";
        
        if ($destContent === $context['test2Data']) {
            echo "     ✓ Content matches perfectly!\n\n";
        } else {
            echo "     ✗ Content mismatch!\n\n";
        }
        
        return $context;
    })
    ->then(function ($context) {
        echo "2.4. Cleaning up test 2...\n";
        $context['source2Stream']->close();
        $context['dest2Stream']->close();
        @unlink($context['source2Path']);
        @unlink($context['dest2Path']);
        echo "     ✓ Cleanup complete\n\n";
    })
    ->then(function () {
        echo "\n" . str_repeat("=", 60) . "\n\n";
        
        // Test 3: Pipe with large data
        echo "Test 3: Pipe with large data (stress test)\n";
        echo "========================================\n\n";
        
        $source3Path = sys_get_temp_dir() . '/pipe3_source_' . uniqid() . '.txt';
        $source3Resource = fopen($source3Path, 'w+');
        $source3Stream = new DuplexStream($source3Resource);
        
        $dest3Path = sys_get_temp_dir() . '/pipe3_dest_' . uniqid() . '.txt';
        $dest3Resource = fopen($dest3Path, 'w');
        $dest3Stream = new WritableStream($dest3Resource);
        
        // Create large test data (1MB)
        $largeData = str_repeat("This is a test line with some content.\n", 25000); // ~1MB
        $expectedSize = strlen($largeData);
        
        echo "3.1. Writing large data to source ($expectedSize bytes)...\n";
        
        return compact('source3Stream', 'dest3Stream', 'source3Path', 'dest3Path', 'source3Resource', 'largeData', 'expectedSize');
    })
    ->then(function ($context) {
        return $context['source3Stream']->write($context['largeData'])
            ->then(function ($bytes) use ($context) {
                echo "     ✓ Written $bytes bytes\n\n";
                return $context;
            });
    })
    ->then(function ($context) {
        fseek($context['source3Resource'], 0, SEEK_SET);
        
        echo "3.2. Piping large data...\n";
        $startTime = microtime(true);
        
        return $context['source3Stream']->pipe($context['dest3Stream'], ['end' => true])
            ->then(function ($totalBytes) use ($startTime, $context) {
                $elapsed = microtime(true) - $startTime;
                $context['totalBytes'] = $totalBytes;
                $context['elapsed'] = $elapsed;
                return $context;
            });
    })
    ->then(function ($context) {
        echo "     ✓ Pipe completed in " . number_format($context['elapsed'], 4) . " seconds\n";
        echo "     ✓ Total transferred: {$context['totalBytes']} bytes\n\n";
        
        echo "3.3. Verifying destination...\n";
        $actualSize = filesize($context['dest3Path']);
        echo "     Destination file size: $actualSize bytes\n";
        
        if ($actualSize === $context['expectedSize']) {
            echo "     ✓ Size matches! Large data pipe successful!\n\n";
        } else {
            echo "     ✗ Size mismatch! Expected: {$context['expectedSize']}, Got: $actualSize\n\n";
        }
        
        return $context;
    })
    ->then(function ($context) {
        echo "3.4. Cleaning up test 3...\n";
        $context['source3Stream']->close();
        @unlink($context['source3Path']);
        @unlink($context['dest3Path']);
        echo "     ✓ Cleanup complete\n\n";
    })
    ->then(function () {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "\n✓ All piping tests completed successfully!\n\n";
    })
    ->catch(function ($error) {
        echo "\n✗ Error occurred: " . $error->getMessage() . "\n";
        echo "Stack trace:\n" . $error->getTraceAsString() . "\n";
    });

Loop::run();