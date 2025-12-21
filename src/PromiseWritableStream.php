<?php

declare(strict_types=1);

namespace Hibla\Stream;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Interfaces\PromiseWritableStreamInterface;
use Hibla\Stream\Traits\PromiseHelperTrait;

class PromiseWritableStream extends WritableResourceStream implements PromiseWritableStreamInterface
{
    use PromiseHelperTrait;

    /**
     * Creates a promise-based writable stream.
     *
     * @param resource $resource A writable PHP stream resource
     * @param int $softLimit The size of the write buffer (in bytes) at which backpressure is applied
     */
    public function __construct($resource, int $softLimit = 65536)
    {
        parent::__construct($resource, $softLimit);
    }

    /**
     * Create a new instance from a PHP stream resource.
     *
     * @param resource $resource A writable PHP stream resource
     * @param int $softLimit The size of the write buffer (in bytes) at which backpressure is applied
     * @return self
     */
    public static function fromResource($resource, int $softLimit = 65536): self
    {
        return new self($resource, $softLimit);
    }

    /**
     * @inheritdoc
     */
    public function writeAsync(string $data): PromiseInterface
    {
        if (! $this->isWritable()) {
            return Promise::rejected(new StreamException('Stream is not writable'));
        }

        if ($data === '') {
            return Promise::resolved(0);
        }

        $bytesToWrite = \strlen($data);
        $handler = $this->getHandler();

        parent::write($data);

        if ($handler->getBufferLength() < $this->getSoftLimit()) {
            // @phpstan-ignore-next-line The handler will return the number of bytes written successfully
            return Promise::resolved($bytesToWrite);
        }

        /** @var Promise<int> $promise */
        $promise = new Promise();
        
        $handler->queueWrite($promise, $bytesToWrite);

        $promise->onCancel(function () use ($promise, $handler): void {
            $handler->cancelWrite($promise);
        });

        return $promise;
    }

    /**
     * @inheritdoc
     */
    public function writeLineAsync(string $data): PromiseInterface
    {
        return $this->writeAsync($data . "\n");
    }

    /**
     * @inheritdoc
     */
    public function endAsync(?string $data = null): PromiseInterface
    {
        if ($this->isEnding() || ! $this->isWritable()) {
            return $this->createResolvedVoidPromise();
        }

        /** @var Promise<void> $promise */
        $promise = new Promise();
        $cancelled = false;

        $finishHandler = function () use ($promise, &$cancelled): void {
            // @phpstan-ignore-next-line promise can be cancelled at runtime
            if ($cancelled) {
                return;
            }
            $promise->resolve(null);
        };

        $errorHandler = function ($error) use ($promise, &$cancelled, $finishHandler): void {
            // @phpstan-ignore-next-line promise can be cancelled at runtime
            if ($cancelled) {
                return;
            }
            $this->removeListener('finish', $finishHandler);
            $promise->reject($error);
        };

        $this->once('finish', $finishHandler);
        $this->on('error', $errorHandler);

        $promise->onCancel(function () use (&$cancelled, $finishHandler, $errorHandler): void {
            $cancelled = true;
            $this->removeListener('finish', $finishHandler);
            $this->removeListener('error', $errorHandler);
        });

        if ($data !== null && $data !== '') {
            $this->writeAsync($data)->then(function () {
                parent::end();
            })->catch(function ($error) use ($promise): void {
                parent::end();
                $promise->reject($error);
            });
        } else {
            parent::end();
        }

        return $promise;
    }
}