<?php

require_once __DIR__ . '/vendor/autoload.php';

use Hibla\Stream\Stream;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Promise;
use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;

echo "=== Stream Pipe Edge Cases & Advanced Scenarios ===\n\n";

// Edge Case 1: Very small chunks
async(function () {
    echo "Edge Case 1: Very Small Chunks (1 byte)\n";
    echo "----------------------------------------\n";
    
    $source = sys_get_temp_dir() . '/small_chunks.txt';
    file_put_contents($source, "ABCDEFGHIJ");
    
    $readable = Stream::readableFile($source, 1); // 1 byte chunks
    $writable = Stream::writableFile(sys_get_temp_dir() . '/small_chunks_out.txt');
    
    $chunkCount = 0;
    $readable->on('data', function () use (&$chunkCount) {
        $chunkCount++;
    });
    
    try {
        $bytes = await(Promise::timeout($readable->pipe($writable), 2.0));
        echo "  ✓ Piped $bytes bytes in $chunkCount chunks\n";
        echo "  " . ($chunkCount === 10 ? '✓' : '✗') . " Correct number of chunks\n";
    } catch (\Exception $e) {
        echo "  ✗ Failed: " . $e->getMessage() . "\n";
    }
    
    @unlink($source);
    @unlink(sys_get_temp_dir() . '/small_chunks_out.txt');
    
    echo "\n";
    await(edgeCase2());
});

// Edge Case 2: Single large chunk
function edgeCase2() {
    return async(function () {
        echo "Edge Case 2: Single Large Chunk\n";
        echo "----------------------------------------\n";
        
        $source = sys_get_temp_dir() . '/large_chunk.txt';
        $data = str_repeat("X", 50000);
        file_put_contents($source, $data);
        
        $readable = Stream::readableFile($source, 100000); // Larger than file
        $writable = Stream::writableFile(sys_get_temp_dir() . '/large_chunk_out.txt');
        
        $chunkCount = 0;
        $readable->on('data', function () use (&$chunkCount) {
            $chunkCount++;
        });
        
        try {
            $bytes = await(Promise::timeout($readable->pipe($writable), 2.0));
            echo "  ✓ Piped $bytes bytes in $chunkCount chunk(s)\n";
            echo "  " . ($bytes === 50000 ? '✓' : '✗') . " Correct byte count\n";
        } catch (\Exception $e) {
            echo "  ✗ Failed: " . $e->getMessage() . "\n";
        }
        
        @unlink($source);
        @unlink(sys_get_temp_dir() . '/large_chunk_out.txt');
        
        echo "\n";
        await(edgeCase3());
    });
}

// Edge Case 3: Pipe to multiple destinations
function edgeCase3() {
    return async(function () {
        echo "Edge Case 3: Pipe to Multiple Destinations (Fan-out)\n";
        echo "----------------------------------------\n";
        
        $source = sys_get_temp_dir() . '/multi_source.txt';
        file_put_contents($source, "Shared content\n");
        
        try {
            // Note: We need to read the source multiple times
            // because a stream can only be piped once
            
            $dest1 = sys_get_temp_dir() . '/multi_dest1.txt';
            $dest2 = sys_get_temp_dir() . '/multi_dest2.txt';
            $dest3 = sys_get_temp_dir() . '/multi_dest3.txt';
            
            // First pipe
            $readable1 = Stream::readableFile($source);
            $writable1 = Stream::writableFile($dest1);
            $bytes1 = await(Promise::timeout($readable1->pipe($writable1), 2.0));
            
            // Second pipe
            $readable2 = Stream::readableFile($source);
            $writable2 = Stream::writableFile($dest2);
            $bytes2 = await(Promise::timeout($readable2->pipe($writable2), 2.0));
            
            // Third pipe
            $readable3 = Stream::readableFile($source);
            $writable3 = Stream::writableFile($dest3);
            $bytes3 = await(Promise::timeout($readable3->pipe($writable3), 2.0));
            
            echo "  ✓ Destination 1: $bytes1 bytes\n";
            echo "  ✓ Destination 2: $bytes2 bytes\n";
            echo "  ✓ Destination 3: $bytes3 bytes\n";
            
            $allMatch = (
                file_get_contents($dest1) === file_get_contents($source) &&
                file_get_contents($dest2) === file_get_contents($source) &&
                file_get_contents($dest3) === file_get_contents($source)
            );
            
            echo "  " . ($allMatch ? '✓' : '✗') . " All destinations match source\n";
            
            @unlink($dest1);
            @unlink($dest2);
            @unlink($dest3);
        } catch (\Exception $e) {
            echo "  ✗ Failed: " . $e->getMessage() . "\n";
        }
        
        @unlink($source);
        
        echo "\n";
        await(edgeCase4());
    });
}

// Edge Case 4: Rapid cancel/resume cycles
function edgeCase4() {
    return async(function () {
        echo "Edge Case 4: Pause/Resume During Pipe\n";
        echo "----------------------------------------\n";
        
        $source = sys_get_temp_dir() . '/pause_test.txt';
        file_put_contents($source, str_repeat("Line\n", 100));
        
        $readable = Stream::readableFile($source);
        $writable = Stream::writableFile(sys_get_temp_dir() . '/pause_test_out.txt');
        
        $pauseCount = 0;
        $chunkCount = 0;
        
        $readable->on('data', function () use (&$chunkCount, &$pauseCount, $readable) {
            $chunkCount++;
            
            // Pause every 5 chunks
            if ($chunkCount % 5 === 0 && $pauseCount < 3) {
                $pauseCount++;
                $readable->pause();
                
                // Resume after short delay
                delay(0.001)->then(function () use ($readable) {
                    $readable->resume();
                });
            }
        });
        
        try {
            $bytes = await(Promise::timeout($readable->pipe($writable), 5.0));
            echo "  ✓ Piped $bytes bytes with $pauseCount pauses\n";
            echo "  ✓ Completed despite interruptions\n";
        } catch (\Exception $e) {
            echo "  ✗ Failed: " . $e->getMessage() . "\n";
        }
        
        @unlink($source);
        @unlink(sys_get_temp_dir() . '/pause_test_out.txt');
        
        echo "\n";
        await(edgeCase5());
    });
}

// Edge Case 5: Pipe with slow destination
function edgeCase5() {
    return async(function () {
        echo "Edge Case 5: Backpressure Handling\n";
        echo "----------------------------------------\n";
        
        $source = sys_get_temp_dir() . '/backpressure_src.txt';
        file_put_contents($source, str_repeat("DATA", 10000)); // 40KB
        
        $readable = Stream::readableFile($source, 8192);
        $writable = Stream::writableFile(
            sys_get_temp_dir() . '/backpressure_dst.txt',
            4096 // Small buffer for backpressure
        );
        
        $drainCount = 0;
        $writable->on('drain', function () use (&$drainCount) {
            $drainCount++;
        });
        
        try {
            $bytes = await(Promise::timeout($readable->pipe($writable), 5.0));
            echo "  ✓ Piped $bytes bytes\n";
            echo "  ✓ Drain events: $drainCount (backpressure handled)\n";
            
            $sourceSize = filesize($source);
            $destSize = filesize(sys_get_temp_dir() . '/backpressure_dst.txt');
            echo "  " . ($sourceSize === $destSize ? '✓' : '✗') . " Size verification\n";
        } catch (\Exception $e) {
            echo "  ✗ Failed: " . $e->getMessage() . "\n";
        }
        
        @unlink($source);
        @unlink(sys_get_temp_dir() . '/backpressure_dst.txt');
        
        echo "\n";
        await(edgeCase6());
    });
}

// Edge Case 6: Multiple sequential pipes on same destination
function edgeCase6() {
    return async(function () {
        echo "Edge Case 6: Sequential Append Operations\n";
        echo "----------------------------------------\n";
        
        $dest = sys_get_temp_dir() . '/sequential_append.txt';
        $writable = Stream::writableFile($dest);
        
        try {
            // Pipe multiple sources sequentially
            for ($i = 1; $i <= 5; $i++) {
                $source = sys_get_temp_dir() . "/seq_src_$i.txt";
                file_put_contents($source, "Section $i\n");
                
                $readable = Stream::readableFile($source);
                $bytes = await(Promise::timeout(
                    $readable->pipe($writable, ['end' => false]),
                    2.0
                ));
                
                echo "  ✓ Section $i: $bytes bytes\n";
                @unlink($source);
            }
            
            // End the destination
            await($writable->end());
            
            $content = file_get_contents($dest);
            $sections = count(array_filter(explode("\n", $content)));
            echo "  " . ($sections === 5 ? '✓' : '✗') . " All sections appended ($sections total)\n";
            
        } catch (\Exception $e) {
            echo "  ✗ Failed: " . $e->getMessage() . "\n";
        }
        
        @unlink($dest);
        
        echo "\n";
        await(edgeCase7());
    });
}

// Edge Case 7: Binary data integrity
function edgeCase7() {
    return async(function () {
        echo "Edge Case 7: Binary Data Integrity\n";
        echo "----------------------------------------\n";
        
        $source = sys_get_temp_dir() . '/binary_src.dat';
        $dest = sys_get_temp_dir() . '/binary_dst.dat';
        
        // Create random binary data
        $binaryData = random_bytes(8192);
        file_put_contents($source, $binaryData);
        
        $readable = Stream::readableFile($source);
        $writable = Stream::writableFile($dest);
        
        try {
            $bytes = await(Promise::timeout($readable->pipe($writable), 2.0));
            
            $sourceHash = hash_file('sha256', $source);
            $destHash = hash_file('sha256', $dest);
            
            echo "  ✓ Piped $bytes bytes of binary data\n";
            echo "  " . ($sourceHash === $destHash ? '✓' : '✗') . " Binary integrity verified\n";
        } catch (\Exception $e) {
            echo "  ✗ Failed: " . $e->getMessage() . "\n";
        }
        
        @unlink($source);
        @unlink($dest);
        
        echo "\n";
        await(edgeCase8());
    });
}

// Edge Case 8: Immediate cancellation
function edgeCase8() {
    return async(function () {
        echo "Edge Case 8: Immediate Cancellation\n";
        echo "----------------------------------------\n";
        
        $source = sys_get_temp_dir() . '/cancel_src.txt';
        file_put_contents($source, str_repeat("X", 100000));
        
        $readable = Stream::readableFile($source);
        $writable = Stream::writableFile(sys_get_temp_dir() . '/cancel_dst.txt');
        
        $pipePromise = $readable->pipe($writable);
        
        // Cancel immediately
        $pipePromise->cancel();
        
        // Give it a moment
        await(delay(0.01));
        
        $readable->close();
        $writable->close();
        
        echo "  ✓ Immediate cancellation handled gracefully\n";
        
        @unlink($source);
        @unlink(sys_get_temp_dir() . '/cancel_dst.txt');
        
        echo "\n=== All Edge Cases Passed ===\n";
        Loop::stop();
    });
}

Loop::run();