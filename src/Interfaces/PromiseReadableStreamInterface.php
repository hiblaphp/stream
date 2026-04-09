<?php

declare(strict_types=1);

namespace Hibla\Stream\Interfaces;

/**
 * A readable stream that additionally exposes a promise-based API.
 */
interface PromiseReadableStreamInterface extends ReadableStreamInterface, PromiseReadableInterface, PromisePipableInterface {}
