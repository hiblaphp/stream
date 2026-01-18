<?php

declare(strict_types=1);

namespace Hibla\Stream\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Exceptions\StreamException;

class WritableStreamHandler
{
    /** 
     * @var array<int, array{promise: Promise<int>, bytes: int}> 
     */
    private array $writeQueue = [];

    private string $writeBuffer = '';

    private int $totalWritten = 0;

    private ?string $watcherId = null;

    /**
     * @param resource $resource
     * @param callable(string, mixed=): void $emitCallback
     * @param callable(): void $closeCallback
     * @param callable(): bool $isEndingCallback
     */
    public function __construct(
        private $resource,
        private int $softLimit,
        private $emitCallback,
        private $closeCallback,
        private $isEndingCallback
    ) {}

    public function getBufferLength(): int
    {
        return \strlen($this->writeBuffer);
    }

    public function bufferData(string $data): void
    {
        $this->writeBuffer .= $data;
    }

    public function clearBuffer(): void
    {
        $this->writeBuffer = '';
    }

    /**
     * @param Promise<int> $promise 
     * @param int $bytesToWrite
     */
    public function queueWrite(Promise $promise, int $bytesToWrite): void
    {
        $this->writeQueue[] = ['promise' => $promise, 'bytes' => $bytesToWrite];
    }

    /**
     * @param PromiseInterface<int> $promise
     */
    public function cancelWrite(PromiseInterface $promise): void
    {
        foreach ($this->writeQueue as $index => $item) {
            if ($item['promise'] === $promise) {
                unset($this->writeQueue[$index]);
                return;
            }
        }
    }

    public function startWatching(bool $writable, bool $ending, bool $closed): void
    {
        if ($this->watcherId !== null || $closed || $this->writeBuffer === '') {
            return;
        }

        if (! $writable && ! $ending) {
            return;
        }

        $this->watcherId = Loop::addWriteWatcher(
            $this->resource,
            fn() => $this->handleWritable(),
        );
    }

    public function stopWatching(): void
    {
        if ($this->watcherId !== null) {
            Loop::removeWriteWatcher($this->watcherId);
            $this->watcherId = null;
        }
    }

    public function handleWritable(): void
    {
        if ($this->writeBuffer === '') {
            return;
        }

        $written = @fwrite($this->resource, $this->writeBuffer);

        if ($written === false || $written === 0) {
            $error = new StreamException('Failed to write to stream');


            ($this->emitCallback)('error', $error);

            $this->rejectAllPending($error);

            ($this->closeCallback)();

            return;
        }

        $wasAboveLimit = \strlen($this->writeBuffer) >= $this->softLimit;
        $this->writeBuffer = substr($this->writeBuffer, $written);
        $this->totalWritten += $written;
        $isNowBelowLimit = \strlen($this->writeBuffer) < $this->softLimit;

        // Check if we crossed the threshold (Backpressure release)
        if (($wasAboveLimit && $isNowBelowLimit) || $this->writeBuffer === '') {
            ($this->emitCallback)('drain');

            // RESOLVE QUEUE: Space is available, notify waiting promises
            $this->resolvePending();
        }

        if ($this->writeBuffer === '') {
            $this->stopWatching();

            if (($this->isEndingCallback)()) {
                ($this->emitCallback)('finish');
            }
        }
    }

    public function isFullyDrained(): bool
    {
        return $this->writeBuffer === '';
    }

    public function getTotalWritten(): int
    {
        return $this->totalWritten;
    }

    public function rejectAllPending(\Throwable $error): void
    {
        if (\count($this->writeQueue) === 0) {
            return;
        }

        $queue = $this->writeQueue;
        $this->writeQueue = [];

        foreach ($queue as $item) {
            if (!$item['promise']->isCancelled()) {
                $item['promise']->reject($error);
            }
        }
    }

    private function resolvePending(): void
    {
        if (\count($this->writeQueue) === 0) {
            return;
        }

        $queue = $this->writeQueue;
        $this->writeQueue = [];

        foreach ($queue as $item) {
            if (!$item['promise']->isCancelled()) {
                $item['promise']->resolve($item['bytes']);
            }
        }
    }
}
