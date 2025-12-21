<?php

declare(strict_types=1);

namespace Hibla\Stream\Traits;

use Hibla\Promise\Promise;
use Hibla\Promise\Interfaces\PromiseInterface;

trait PromiseHelperTrait
{
    /**
     * Create a resolved Promise
     *
     * @template TValue
     * @param TValue $value
     * @return PromiseInterface<TValue>
     */
    private function createResolvedPromise(mixed $value): PromiseInterface
    {
        /** @var Promise<TValue> $promise */
        $promise = new Promise();
        $promise->resolve($value);

        return $promise;
    }

    /**
     * Create a rejected Promise
     *
     * @return PromiseInterface<never>
     */
    private function createRejectedPromise(\Throwable $reason): PromiseInterface
    {
        /** @var Promise<never> $promise */
        $promise = new Promise();
        $promise->reject($reason);

        return $promise;
    }

    /**
     * Create a resolved void Promise
     *
     * @return PromiseInterface<void>
     */
    private function createResolvedVoidPromise(): PromiseInterface
    {
        /** @var Promise<void> $promise */
        $promise = new Promise();
        $promise->resolve(null);

        return $promise;
    }

    /**
     * Create a resolved promise with string|null value.
     *
     * @param string|null $value
     * @return PromiseInterface<string|null>
     */
    private function createResolvedStringOrNullPromise(?string $value): PromiseInterface
    {
        /** @var Promise<string|null> $promise */
        $promise = new Promise();
        $promise->resolve($value);

        return $promise;
    }
}
