<?php

declare(strict_types=1);

namespace Hibla\Stream\Interfaces;

/**
 * Defines the contract for readable streams that support seeking and telling positions.
 */
interface SeekableStreamInterface extends ReadableStreamInterface
{
    /**
     * Reposition the stream pointer.
     *
     * @param int $offset The offset to seek to.
     * @param int $whence Where to start seeking (SEEK_SET, SEEK_CUR, SEEK_END).
     * @return bool True on success, false if the stream is non-seekable.
     * @throws \Hibla\Stream\Exceptions\StreamException If the stream is closed or invalid.
     */
    public function seek(int $offset, int $whence = SEEK_SET): bool;

    /**
     * Get the current position in the stream.
     *
     * @return int|false The current position in bytes, or false on failure.
     * @throws \Hibla\Stream\Exceptions\StreamException If the stream is closed or invalid.
     */
    public function tell(): int|false;
}