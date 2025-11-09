<?php

require_once __DIR__ . '/vendor/autoload.php';

use Hibla\Stream\DuplexStream;
use Hibla\EventLoop\Loop;

echo "=== DuplexStream Simple Test ===\n\n";

// Create a temporary file for testing
$tempPath = sys_get_temp_dir() . '/duplex_test_' . uniqid() . '.txt';
$resource = fopen($tempPath, 'w+');

if (!$resource) {
    die("Failed to open temp file\n");
}

echo "1. Testing DuplexStream creation...\n";
try {
    $duplex = new DuplexStream($resource);
    echo "   ✓ DuplexStream created successfully\n\n";
} catch (\Exception $e) {
    echo "   ✗ Failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Setup event listeners first
echo "2. Setting up event listeners...\n";
$duplex->on('data', function ($data) {
    echo "   ✓ 'data' event fired: " . trim($data) . "\n";
});

$duplex->on('drain', function () {
    echo "   ✓ 'drain' event fired\n";
});

$duplex->on('close', function () {
    echo "   ✓ 'close' event fired\n";
    echo "\n=== All tests completed ===\n";
});

echo "   ✓ Event listeners registered\n\n";

// Now chain all our tests using promises
echo "3. Starting promise chain tests...\n\n";

$writeData = "Hello, DuplexStream!\n";

$duplex->write($writeData)
    ->then(function ($bytes) use ($writeData) {
        echo "   ✓ Test 3.1: Written $bytes bytes (expected: " . strlen($writeData) . ")\n";
        return $bytes;
    })
    ->then(function () use ($duplex) {
        echo "   ✓ Test 3.2: Testing writeLine...\n";
        return $duplex->writeLine("Second line");
    })
    ->then(function ($bytes) {
        echo "   ✓ Test 3.3: writeLine completed ($bytes bytes)\n";
        return $bytes;
    })
    ->then(function () use ($duplex, $resource) {
        echo "   ✓ Test 3.4: Seeking to start of file...\n";
        fseek($resource, 0, SEEK_SET);
        return true;
    })
    ->then(function () use ($duplex) {
        echo "   ✓ Test 3.5: Reading data...\n";
        return $duplex->read();
    })
    ->then(function ($data) use ($duplex) {
        if ($data !== null) {
            echo "   ✓ Test 3.6: Read first chunk: " . trim($data) . "\n";
        } else {
            echo "   ✗ Test 3.6: Read returned null\n";
        }
        return $data;
    })
    ->then(function () use ($duplex) {
        echo "   ✓ Test 3.7: Reading second chunk...\n";
        return $duplex->read();
    })
    ->then(function ($data) {
        if ($data !== null) {
            echo "   ✓ Test 3.8: Read second chunk: " . trim($data) . "\n";
        } else {
            echo "   ✓ Test 3.8: Second read returned null (expected)\n";
        }
        return $data;
    })
    ->then(function () use ($duplex) {
        echo "\n4. Testing state checks...\n";
        echo "   - isReadable: " . ($duplex->isReadable() ? 'true' : 'false') . "\n";
        echo "   - isWritable: " . ($duplex->isWritable() ? 'true' : 'false') . "\n";
        echo "   - isPaused: " . ($duplex->isPaused() ? 'true' : 'false') . "\n";
        echo "   ✓ State checks completed\n";
        return true;
    })
    ->then(function () use ($duplex) {
        echo "\n5. Testing pause/resume...\n";
        $duplex->pause();
        echo "   ✓ Stream paused (isPaused: " . ($duplex->isPaused() ? 'true' : 'false') . ")\n";
        
        $duplex->resume();
        echo "   ✓ Stream resumed (isPaused: " . ($duplex->isPaused() ? 'true' : 'false') . ")\n";
        return true;
    })
    ->then(function () use ($duplex, $resource) {
        echo "\n6. Testing another write after state changes...\n";
        fseek($resource, 0, SEEK_END); // Move to end
        return $duplex->write("Third line\n");
    })
    ->then(function ($bytes) {
        echo "   ✓ Third write completed ($bytes bytes)\n";
        return $bytes;
    })
    ->then(function () use ($duplex) {
        echo "\n7. Testing end()...\n";
        return $duplex->end("Final line\n");
    })
    ->then(function () use ($duplex) {
        echo "   ✓ end() completed successfully\n";
        echo "   - isEnding: " . ($duplex->isEnding() ? 'true' : 'false') . "\n";
        echo "   - isWritable: " . ($duplex->isWritable() ? 'true' : 'false') . "\n";
        return true;
    })
    ->then(function () use ($duplex, $tempPath) {
        echo "\n8. Closing stream...\n";
        $duplex->close();
        @unlink($tempPath);
    })
    ->catch(function ($error) use ($tempPath) {
        echo "   ✗ Error occurred: " . $error->getMessage() . "\n";
        echo "   Stack trace:\n" . $error->getTraceAsString() . "\n";
        @unlink($tempPath);
    });

// Run the event loop
Loop::run();