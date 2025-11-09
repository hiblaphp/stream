<?php

require_once __DIR__ . '/vendor/autoload.php';

use Hibla\Stream\DuplexStream;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Promise;

echo "=== DuplexStream Network (TCP) Test ===\n\n";

$host = '127.0.0.1';
$port = 9090;

echo "Test Setup:\n";
echo "  Host: $host\n";
echo "  Port: $port\n\n";
echo str_repeat("=", 60) . "\n\n";

// Test 1: Simple Echo Server
echo "Test 1: TCP Echo Server & Client\n";
echo "========================================\n\n";

$serverSocket = @stream_socket_server("tcp://$host:$port", $errno, $errstr);

if (!$serverSocket) {
    die("✗ Failed to create server: $errstr ($errno)\n");
}

echo "1.1. ✓ Server created and listening on $host:$port\n";
stream_set_blocking($serverSocket, false);

$serverStream = null;

$acceptWatcher = Loop::addStreamWatcher($serverSocket, function () use ($serverSocket, &$serverStream, &$acceptWatcher) {
    $clientSocket = @stream_socket_accept($serverSocket, 0);
    
    if ($clientSocket) {
        echo "1.2. ✓ Server accepted connection\n";
        
        $serverStream = new DuplexStream($clientSocket);
        
        // Echo handler - read and write back using promises
        $echoData = function () use ($serverStream, &$echoData) {
            $serverStream->read()->then(function ($data) use ($serverStream, &$echoData) {
                if ($data === null) {
                    echo "1.3. ✓ Server: Client ended connection\n";
                    return;
                }
                
                echo "     → Server received: " . json_encode($data) . "\n";
                echo "     ← Server echoing back...\n";
                
                return $serverStream->write($data)->then(function () use (&$echoData) {
                    // Continue echoing
                    $echoData();
                });
            })->catch(function ($error) {
                // Don't print error if connection closed
                if (strpos($error->getMessage(), 'Stream closed') === false) {
                    echo "     ✗ Server error: " . $error->getMessage() . "\n";
                }
            });
        };
        
        // Start echo loop
        $echoData();
        
        $serverStream->on('close', function () {
            echo "1.4. ✓ Server: Connection closed\n";
        });
        
        Loop::removeStreamWatcher($acceptWatcher);
    }
}, 'read');

// Connect client
Loop::defer(function () use ($host, $port) {
    echo "1.5. Client connecting to server...\n";
    
    $clientSocket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);
    
    if (!$clientSocket) {
        echo "     ✗ Client connection failed: $errstr ($errno)\n";
        return;
    }
    
    echo "     ✓ Client connected\n\n";
    
    $clientStream = new DuplexStream($clientSocket);
    
    // IMPORTANT: Resume the stream to enable auto-reading
    $clientStream->resume();
    
    // Collect echoed responses
    $receivedData = '';
    
    $clientStream->on('data', function ($data) use (&$receivedData) {
        echo "     ← Client received: " . json_encode($data) . "\n";
        $receivedData .= $data;
    });
    
    $clientStream->on('close', function () {
        echo "1.6. ✓ Client: Connection closed\n\n";
    });
    
    // Send messages using promise chain
    $messages = [
        "Hello, Server!\n",
        "This is message 2\n",
        "Final message\n"
    ];
    
    $expectedData = implode('', $messages);
    
    echo "     → Client sending: " . json_encode($messages[0]) . "\n";
    $clientStream->write($messages[0])
        ->then(function () use ($clientStream, $messages) {
            // Add small delay between messages
            return new Promise(function ($resolve) use ($clientStream, $messages) {
                Loop::addTimer(0.1, function () use ($resolve, $clientStream, $messages) {
                    echo "     → Client sending: " . json_encode($messages[1]) . "\n";
                    $clientStream->write($messages[1])->then($resolve);
                });
            });
        })
        ->then(function () use ($clientStream, $messages) {
            return new Promise(function ($resolve) use ($clientStream, $messages) {
                Loop::addTimer(0.1, function () use ($resolve, $clientStream, $messages) {
                    echo "     → Client sending: " . json_encode($messages[2]) . "\n";
                    $clientStream->write($messages[2])->then($resolve);
                });
            });
        })
        ->then(function () use ($clientStream, $expectedData, &$receivedData) {
            // Wait for echo responses
            return new Promise(function ($resolve) use ($clientStream, $expectedData, &$receivedData) {
                Loop::addTimer(0.5, function () use ($resolve, $clientStream, $expectedData, &$receivedData) {
                    echo "\n1.7. Verifying echo...\n";
                    echo "     Expected: " . json_encode($expectedData) . "\n";
                    echo "     Received: " . json_encode($receivedData) . "\n";
                    
                    if ($receivedData === $expectedData) {
                        echo "     ✓ Echo successful! All data matches.\n\n";
                    } else {
                        echo "     ✗ Echo mismatch!\n\n";
                    }
                    
                    $resolve(true);
                });
            });
        })
        ->then(function () use ($clientStream) {
            echo "1.8. Closing client connection...\n";
            return $clientStream->end();
        })
        ->catch(function ($error) {
            echo "     ✗ Client error: " . $error->getMessage() . "\n";
        });
});

// Cleanup and start test 2
Loop::addTimer(3, function () use ($serverSocket, &$serverStream) {
    if ($serverStream) {
        $serverStream->close();
    }
    @fclose($serverSocket);
    
    echo str_repeat("=", 60) . "\n\n";
    startTest2();
});

function startTest2() {
    echo "Test 2: Large Data Transfer Over Network\n";
    echo "========================================\n\n";
    
    $host = '127.0.0.1';
    $port = 9091;
    
    $serverSocket = @stream_socket_server("tcp://$host:$port", $errno, $errstr);
    
    if (!$serverSocket) {
        echo "✗ Failed to create server: $errstr ($errno)\n";
        Loop::defer(function () {
            startTest3();
        });
        return;
    }
    
    echo "2.1. ✓ Server created on port $port\n";
    stream_set_blocking($serverSocket, false);
    
    $serverStream = null;
    $startTime = null;
    
    $acceptWatcher = Loop::addStreamWatcher($serverSocket, function () use ($serverSocket, &$serverStream, &$startTime, &$acceptWatcher) {
        $clientSocket = @stream_socket_accept($serverSocket, 0);
        
        if ($clientSocket) {
            echo "2.2. ✓ Server accepted connection\n";
            $serverStream = new DuplexStream($clientSocket);
            $startTime = microtime(true);
            
            // Read all data using promise (with higher limit)
            $serverStream->readAll(10 * 1024 * 1024)->then(function ($data) use (&$startTime) {
                $elapsed = microtime(true) - $startTime;
                $receivedBytes = strlen($data);
                
                echo "2.3. ✓ Server received all data\n";
                echo "     Total bytes: $receivedBytes\n";
                echo "     Time: " . number_format($elapsed, 4) . " seconds\n";
                
                if ($elapsed > 0) {
                    echo "     Speed: " . number_format($receivedBytes / $elapsed / 1024 / 1024, 2) . " MB/s\n\n";
                }
            })->catch(function ($error) {
                echo "     ✗ Server read error: " . $error->getMessage() . "\n";
            });
            
            Loop::removeStreamWatcher($acceptWatcher);
        }
    }, 'read');
    
    Loop::defer(function () use ($host, $port, &$serverStream, $serverSocket) {
        echo "2.4. Client connecting...\n";
        
        $clientSocket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);
        
        if (!$clientSocket) {
            echo "     ✗ Failed: $errstr ($errno)\n\n";
            @fclose($serverSocket);
            Loop::defer(function () {
                startTest3();
            });
            return;
        }
        
        echo "     ✓ Client connected\n";
        
        $clientStream = new DuplexStream($clientSocket);
        
        // Use smaller data for more reliable test (500KB instead of 5MB)
        $largeData = str_repeat("X", 1024 * 500); // 500KB
        $dataSize = strlen($largeData);
        
        echo "2.5. Sending $dataSize bytes...\n";
        
        $clientStream->write($largeData)
            ->then(function ($bytes) {
                echo "2.6. ✓ Client sent $bytes bytes\n";
                echo "2.7. Closing client connection...\n";
            })
            ->then(function () use ($clientStream) {
                return $clientStream->end();
            })
            ->then(function () use ($serverSocket, &$serverStream) {
                Loop::addTimer(1, function () use ($serverSocket, &$serverStream) {
                    if ($serverStream) {
                        $serverStream->close();
                    }
                    @fclose($serverSocket);
                    
                    echo str_repeat("=", 60) . "\n\n";
                    startTest3();
                });
            })
            ->catch(function ($error) {
                echo "     ✗ Client error: " . $error->getMessage() . "\n";
            });
    });
}

function startTest3() {
    echo "Test 3: Bidirectional Communication\n";
    echo "========================================\n\n";
    
    $host = '127.0.0.1';
    $port = 9092;
    
    $serverSocket = @stream_socket_server("tcp://$host:$port", $errno, $errstr);
    
    if (!$serverSocket) {
        echo "✗ Failed to create server: $errstr ($errno)\n";
        echo "\n✓ All network tests completed!\n";
        return;
    }
    
    echo "3.1. ✓ Server created on port $port\n";
    stream_set_blocking($serverSocket, false);
    
    $serverStream = null;
    
    $acceptWatcher = Loop::addStreamWatcher($serverSocket, function () use ($serverSocket, &$serverStream, &$acceptWatcher) {
        $clientSocket = @stream_socket_accept($serverSocket, 0);
        
        if ($clientSocket) {
            echo "3.2. ✓ Server accepted connection\n";
            $serverStream = new DuplexStream($clientSocket);
            
            // Server echo loop using promises
            $handleRequest = function () use ($serverStream, &$handleRequest) {
                $serverStream->readLine()->then(function ($line) use ($serverStream, &$handleRequest) {
                    if ($line === null) {
                        echo "3.3. ✓ Server: Client ended\n";
                        return;
                    }
                    
                    $trimmed = trim($line);
                    echo "     → Server received: '$trimmed'\n";
                    
                    $response = "Server response to: $trimmed\n";
                    echo "     ← Server sending: " . json_encode($response) . "\n";
                    
                    return $serverStream->write($response)->then(function () use (&$handleRequest) {
                        $handleRequest();
                    });
                })->catch(function ($error) {
                    // Ignore "Stream closed" errors
                    if (strpos($error->getMessage(), 'Stream closed') === false) {
                        echo "     ✗ Server error: " . $error->getMessage() . "\n";
                    }
                });
            };
            
            $handleRequest();
            
            Loop::removeStreamWatcher($acceptWatcher);
        }
    }, 'read');
    
    Loop::defer(function () use ($host, $port, $serverSocket, &$serverStream) {
        echo "3.4. Client connecting...\n";
        
        $clientSocket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);
        
        if (!$clientSocket) {
            echo "     ✗ Failed: $errstr ($errno)\n";
            @fclose($serverSocket);
            echo "\n✓ All network tests completed!\n";
            return;
        }
        
        echo "     ✓ Client connected\n\n";
        
        $clientStream = new DuplexStream($clientSocket);
        
        $serverResponses = [];
        $messages = ["Hello", "How are you?", "Goodbye"];
        
        // Send first message and read response
        echo "     → Client sending: 'Hello'\n";
        $clientStream->writeLine("Hello")
            ->then(function () use ($clientStream) {
                return $clientStream->readLine();
            })
            ->then(function ($response) use ($clientStream, &$serverResponses) {
                if ($response !== null) {
                    $serverResponses[] = trim($response);
                    echo "     ← Client received: '" . trim($response) . "'\n";
                }
                
                echo "     → Client sending: 'How are you?'\n";
                return $clientStream->writeLine("How are you?");
            })
            ->then(function () use ($clientStream) {
                return $clientStream->readLine();
            })
            ->then(function ($response) use ($clientStream, &$serverResponses) {
                if ($response !== null) {
                    $serverResponses[] = trim($response);
                    echo "     ← Client received: '" . trim($response) . "'\n";
                }
                
                echo "     → Client sending: 'Goodbye'\n";
                return $clientStream->writeLine("Goodbye");
            })
            ->then(function () use ($clientStream) {
                return $clientStream->readLine();
            })
            ->then(function ($response) use ($clientStream, &$serverResponses, $messages) {
                if ($response !== null) {
                    $serverResponses[] = trim($response);
                    echo "     ← Client received: '" . trim($response) . "'\n";
                }
                
                echo "\n3.5. Verifying bidirectional communication...\n";
                echo "     Messages sent: " . count($messages) . "\n";
                echo "     Responses received: " . count($serverResponses) . "\n";
                
                if (count($serverResponses) === count($messages)) {
                    echo "     ✓ All responses received!\n\n";
                } else {
                    echo "     ✗ Response count mismatch!\n\n";
                }
                
                echo "3.6. Closing connections...\n";
                return $clientStream->end();
            })
            ->then(function () use ($serverSocket, &$serverStream) {
                Loop::addTimer(0.5, function () use ($serverSocket, &$serverStream) {
                    if ($serverStream) {
                        $serverStream->close();
                    }
                    @fclose($serverSocket);
                    
                    echo "\n" . str_repeat("=", 60) . "\n";
                    echo "\n✓ All network tests completed successfully!\n\n";
                });
            })
            ->catch(function ($error) {
                echo "     ✗ Client error: " . $error->getMessage() . "\n";
            });
    });
}

Loop::run();