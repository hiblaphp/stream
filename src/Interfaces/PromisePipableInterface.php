<?php

declare(strict_types=1);

namespace Hibla\Stream\Interfaces;

use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Defines the contract for piping data from a readable source to a writable destination.
 * Automatically handles backpressure during the transfer.
 */
interface PromisePipableInterface
{
    /**
     * Forwards all data from this stream to a destination, automatically handling backpressure.
     * This is a highly efficient way to transfer data between streams.
     *
     * @param WritableStreamInterface $destination The stream to receive the data.
     * @param array{end?: bool} $options Configure piping behavior, such as whether to end the destination stream.
     * @return PromiseInterface<int> Resolves with the total number of bytes piped.
     */
    public function pipeAsync(WritableStreamInterface $destination, array $options = []): PromiseInterface;
}