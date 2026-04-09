<?php

declare(strict_types=1);

namespace Hibla\Stream\Interfaces;

/**
 * A writable stream that additionally exposes a promise-based API.
 */
interface PromiseWritableStreamInterface extends WritableStreamInterface, PromiseWritableInterface
{
}
