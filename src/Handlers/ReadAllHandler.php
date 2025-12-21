<?php

declare(strict_types=1);

namespace Hibla\Stream\Handlers;

use Hibla\Promise\Promise;
use Hibla\Promise\Interfaces\PromiseInterface;

class ReadAllHandler
{
    /**
     * @param callable(int|null): PromiseInterface<string|null> $readCallback
     */
    public function __construct(
        private int $chunkSize,
        private $readCallback
    ) {
    }

    /**
     * @return PromiseInterface<string>
     */
    public function readAll(string $initialBuffer, int $maxLength): PromiseInterface
    {
        /** @var Promise<string> $promise */
        $promise = new Promise();
        $buffer = $initialBuffer;
        $cancelled = false;

        /** @var PromiseInterface<string|null>|null $currentReadPromise */
        $currentReadPromise = null;

        $promise->onCancel(function () use (&$cancelled, &$currentReadPromise) {
            $cancelled = true;
            if ($currentReadPromise !== null) {
                $currentReadPromise->cancel();
            }
        });

        $readMore = function () use ($promise, $maxLength, &$buffer, &$readMore, &$cancelled, &$currentReadPromise) {
            if ($cancelled) {
                return;
            }

            if (\strlen($buffer) >= $maxLength) {
                $promise->resolve($buffer);

                return;
            }

            $currentReadPromise = ($this->readCallback)(min($this->chunkSize, $maxLength - \strlen($buffer)));

            $currentReadPromise->then(
                function ($data) use ($promise, &$buffer, &$readMore, &$cancelled) {
                    // @phpstan-ignore-next-line php-stan dont know that cancell flag can change in run time during cancellation
                    if ($cancelled) {
                        return;
                    }

                    if ($data === null) {
                        $promise->resolve($buffer);

                        return;
                    }

                    $buffer .= $data;
                    $readMore();
                }
            )->catch(function ($error) use ($promise, &$cancelled) {
                // @phpstan-ignore-next-line php-stan dont know that cancell flag can change in run time during cancellation
                if ($cancelled) {
                    return;
                }
                $promise->reject($error);
            });
        };

        $readMore();

        return $promise;
    }
}
