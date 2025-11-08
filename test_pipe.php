<?php

require_once __DIR__ . '/vendor/autoload.php';

use Hibla\Stream\Stream;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Promise;
use function Hibla\async;
use function Hibla\await;

echo "=== Real-World Pipe Usage Examples ===\n\n";

// Example 1: File copy utility
async(function () {
    echo "Example 1: File Copy Utility\n";
    echo "----------------------------------------\n";
    
    $source = sys_get_temp_dir() . '/original.txt';
    $backup = sys_get_temp_dir() . '/backup.txt';
    
    file_put_contents($source, str_repeat("Important data\n", 100));
    
    $readable = Stream::readableFile($source);
    $writable = Stream::writableFile($backup);
    
    $startTime = microtime(true);
    
    try {
        $bytes = await(Promise::timeout($readable->pipe($writable), 5.0));
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        
        echo "  ✓ Copied $bytes bytes in {$duration}ms\n";
        echo "  ✓ Backup created successfully\n";
    } catch (\Exception $e) {
        echo "  ✗ Copy failed: " . $e->getMessage() . "\n";
    }
    
    @unlink($source);
    @unlink($backup);
    
    echo "\n";
    await(example2());
});

// Example 2: Log file rotation
function example2() {
    return async(function () {
        echo "Example 2: Log File Rotation\n";
        echo "----------------------------------------\n";
        
        $currentLog = sys_get_temp_dir() . '/app.log';
        $archivedLog = sys_get_temp_dir() . '/app.log.old';
        
        // Simulate log file
        $logEntries = [];
        for ($i = 1; $i <= 50; $i++) {
            $logEntries[] = sprintf("[%s] Log entry %d\n", date('Y-m-d H:i:s'), $i);
        }
        file_put_contents($currentLog, implode('', $logEntries));
        
        echo "  Current log size: " . filesize($currentLog) . " bytes\n";
        
        try {
            // Archive old log
            $readable = Stream::readableFile($currentLog);
            $writable = Stream::writableFile($archivedLog);
            
            $bytes = await(Promise::timeout($readable->pipe($writable), 5.0));
            
            // Truncate current log
            file_put_contents($currentLog, "");
            
            echo "  ✓ Archived $bytes bytes\n";
            echo "  ✓ Current log truncated\n";
            echo "  Archived log size: " . filesize($archivedLog) . " bytes\n";
        } catch (\Exception $e) {
            echo "  ✗ Rotation failed: " . $e->getMessage() . "\n";
        }
        
        @unlink($currentLog);
        @unlink($archivedLog);
        
        echo "\n";
        await(example3());
    });
}

// Example 3: Streaming data transformation
function example3() {
    return async(function () {
        echo "Example 3: Progress Tracking\n";
        echo "----------------------------------------\n";
        
        $source = sys_get_temp_dir() . '/large_file.dat';
        $dest = sys_get_temp_dir() . '/large_file_copy.dat';
        
        // Create 1MB file
        $size = 1024 * 1024;
        file_put_contents($source, random_bytes($size));
        
        $readable = Stream::readableFile($source, 8192);
        $writable = Stream::writableFile($dest, 16384);
        
        $totalBytes = 0;
        $chunks = 0;
        
        $readable->on('data', function ($data) use (&$totalBytes, &$chunks, $size) {
            $totalBytes += strlen($data);
            $chunks++;
            $progress = round(($totalBytes / $size) * 100, 1);
            
            if ($chunks % 20 === 0) {
                echo "\r  Progress: $progress% ($totalBytes/$size bytes)";
            }
        });
        
        try {
            $bytes = await(Promise::timeout($readable->pipe($writable), 10.0));
            echo "\r  ✓ Complete: 100% ($bytes bytes in $chunks chunks)          \n";
            
            $sourceHash = md5_file($source);
            $destHash = md5_file($dest);
            echo "  " . ($sourceHash === $destHash ? '✓' : '✗') . " Integrity verified\n";
            
        } catch (\Exception $e) {
            echo "\n  ✗ Transfer failed: " . $e->getMessage() . "\n";
        }
        
        @unlink($source);
        @unlink($dest);
        
        echo "\n";
        await(example4());
    });
}

// Example 4: Concurrent file operations
function example4() {
    return async(function () {
        echo "Example 4: Concurrent File Operations\n";
        echo "----------------------------------------\n";
        
        $operations = [];
        
        // Create multiple pipe operations
        for ($i = 1; $i <= 5; $i++) {
            $operations[] = async(function () use ($i) {
                $source = sys_get_temp_dir() . "/file_$i.txt";
                $dest = sys_get_temp_dir() . "/copy_$i.txt";
                
                file_put_contents($source, "File $i: " . str_repeat("data ", 1000) . "\n");
                
                $readable = Stream::readableFile($source);
                $writable = Stream::writableFile($dest);
                
                $bytes = await(Promise::timeout($readable->pipe($writable), 5.0));
                
                @unlink($source);
                @unlink($dest);
                
                return ['file' => $i, 'bytes' => $bytes];
            });
        }
        
        try {
            $results = await(Promise::all($operations));
            
            foreach ($results as $result) {
                echo "  ✓ File {$result['file']}: {$result['bytes']} bytes\n";
            }
            
            echo "  ✓ All concurrent operations completed\n";
            
        } catch (\Exception $e) {
            echo "  ✗ Concurrent operations failed: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
        await(example5());
    });
}

// Example 5: Error handling
function example5() {
    return async(function () {
        echo "Example 5: Error Handling\n";
        echo "----------------------------------------\n";
        
        // Test 1: Non-existent source
        try {
            $readable = Stream::readableFile(sys_get_temp_dir() . '/nonexistent.txt');
            echo "  ✗ Should have thrown error\n";
        } catch (\Exception $e) {
            echo "  ✓ Non-existent file caught\n";
        }
        
        // Test 2: Pipe to closed stream
        $source = sys_get_temp_dir() . '/test_error.txt';
        file_put_contents($source, "data");
        
        try {
            $readable = Stream::readableFile($source);
            $writable = Stream::writableFile(sys_get_temp_dir() . '/test_dest_error.txt');
            
            // Close writable before pipe completes
            $pipePromise = $readable->pipe($writable);
            $writable->close();
            
            await(Promise::timeout($pipePromise, 2.0));
            echo "  ✗ Should have caught closed stream\n";
        } catch (\Exception $e) {
            echo "  ✓ Closed stream error caught\n";
        }
        
        @unlink($source);
        @unlink(sys_get_temp_dir() . '/test_dest_error.txt');
        
        echo "\n";
        await(example6());
    });
}

// Example 6: Pipe with transformation
function example6() {
    return async(function () {
        echo "Example 6: Append Operation (end: false)\n";
        echo "----------------------------------------\n";
        
        $source1 = sys_get_temp_dir() . '/part1.txt';
        $source2 = sys_get_temp_dir() . '/part2.txt';
        $merged = sys_get_temp_dir() . '/merged.txt';
        
        file_put_contents($source1, "Part 1 content\n");
        file_put_contents($source2, "Part 2 content\n");
        
        try {
            $writable = Stream::writableFile($merged);
            
            // Pipe first file
            $readable1 = Stream::readableFile($source1);
            $bytes1 = await(Promise::timeout(
                $readable1->pipe($writable, ['end' => false]),
                2.0
            ));
            echo "  ✓ Part 1: $bytes1 bytes\n";
            
            // Pipe second file to same destination
            $readable2 = Stream::readableFile($source2);
            $bytes2 = await(Promise::timeout(
                $readable2->pipe($writable, ['end' => false]),
                2.0
            ));
            echo "  ✓ Part 2: $bytes2 bytes\n";
            
            // Add footer
            await($writable->write("--- End of file ---\n"));
            echo "  ✓ Footer added\n";
            
            // Close the destination
            await($writable->end());
            
            $content = file_get_contents($merged);
            $lines = count(array_filter(explode("\n", $content)));
            echo "  ✓ Total lines in merged file: $lines\n";
            
        } catch (\Exception $e) {
            echo "  ✗ Merge failed: " . $e->getMessage() . "\n";
        }
        
        @unlink($source1);
        @unlink($source2);
        @unlink($merged);
        
        echo "\n=== All Examples Complete ===\n";
        Loop::stop();
    });
}

Loop::run();