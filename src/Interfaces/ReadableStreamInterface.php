<?php

declare(strict_types=1);

namespace Hibla\Stream\Interfaces;

use Evenement\EventEmitterInterface;

/**
 * Defines the contract for a stream that can be read from.
 * It provides an abstraction for consuming data asynchronously and managing flow control.
 */
interface ReadableStreamInterface extends EventEmitterInterface
{
    /**
     * Pipes all data from this stream to the destination stream.
     *
     * This will automatically forward all data from the source stream to the
     * destination stream and manage back-pressure by pausing/resuming appropriately.
     *
     * @param WritableStreamInterface $destination The stream to receive the data.
     * @param array{end?: bool} $options Configure piping behavior. Set 'end' to false to keep destination open after source ends.
     * @return WritableStreamInterface Returns the destination stream for chaining
     */
    public function pipe(WritableStreamInterface $destination, array $options = []): WritableStreamInterface;

    /**
     * Determines if the stream is currently open and available for reading.
     */
    public function isReadable(): bool;

    /**
     * Halts the emission of 'data' events, signaling a need to temporarily stop data flow.
     */
    public function pause(): void;

    /**
     * Resumes the emission of 'data' events, allowing data to flow again.
     */
    public function resume(): void;

    /**
     * Forcefully terminates the stream and closes the underlying resource.
     */
    public function close(): void;
}