<?php

declare(strict_types=1);

namespace Hibla\Stream\Traits;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

trait PromiseHelperTrait
{
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
