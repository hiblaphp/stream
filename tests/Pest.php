<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;

uses()
    ->beforeEach(function () {
        Loop::reset();
    })
    ->afterEach(function () {
        Loop::stop();
        Loop::reset();
    })
    ->in(__DIR__)
;

function cleanupTempFile(string $file): void
{
    if (file_exists($file)) {
        @unlink($file);
    }
}

function createSocketPair(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (! $server) {
        throw new RuntimeException("Failed to create server socket: $errstr");
    }

    $serverAddress = stream_socket_get_name($server, false);

    $client = stream_socket_client("tcp://$serverAddress", $errno, $errstr, 5);

    if (! $client) {
        fclose($server);
        throw new RuntimeException("Failed to create client socket: $errstr");
    }

    $accepted = stream_socket_accept($server, 5);
    if (! $accepted) {
        fclose($client);
        fclose($server);
        throw new RuntimeException('Failed to accept connection');
    }

    fclose($server);

    return [$client, $accepted];
}

function closeSocketPair(array $pair): void
{
    foreach ($pair as $socket) {
        if (is_resource($socket)) {
            @fclose($socket);
        }
    }
}

function createTempFile(string $content = ''): string
{
    $file = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($file, $content);

    return $file;
}
