<?php

declare(strict_types=1);

namespace Hibla\Stream;

use Hibla\Stream\Interfaces\ReadableStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;

final class Util
{
    /**
     * Pipes all the data from the given $source into the $dest
     *
     * @param ReadableStreamInterface $source
     * @param WritableStreamInterface $dest
     * @param array{end?:bool} $options
     * @return WritableStreamInterface $dest stream as-is
     */
    public static function pipe(ReadableStreamInterface $source, WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        // source not readable => NO-OP
        if (! $source->isReadable()) {
            return $dest;
        }

        // destination not writable => just pause() source
        if (! $dest->isWritable()) {
            $source->pause();

            return $dest;
        }

        $dest->emit('pipe', [$source]);

        // forward all source data events as $dest->write()
        $source->on('data', $dataer = function (string $data) use ($source, $dest): void {
            $feedMore = $dest->write($data);
            if (false === $feedMore) {
                $source->pause();
            }
        });

        $dest->on('close', function () use ($source, $dataer): void {
            $source->removeListener('data', $dataer);
            $source->pause();
        });

        // forward destination drain as $source->resume()
        $dest->on('drain', $drainer = function () use ($source): void {
            $source->resume();
        });

        $source->on('close', function () use ($dest, $drainer): void {
            $dest->removeListener('drain', $drainer);
        });

        // forward end event from source as $dest->end()
        $end = $options['end'] ?? true;
        if ($end) {
            $source->on('end', $ender = function () use ($dest): void {
                $dest->end();
            });

            $dest->on('close', function () use ($source, $ender): void {
                $source->removeListener('end', $ender);
            });
        }

        // Start flowing data from source
        $source->resume();

        return $dest;
    }

    /**
     * Forwards events from source to target
     *
     * @param ReadableStreamInterface|WritableStreamInterface $source
     * @param ReadableStreamInterface|WritableStreamInterface $target
     * @param string[] $events
     * @return void
     */
    public static function forwardEvents($source, $target, array $events): void
    {
        foreach ($events as $event) {
            $source->on($event, function (...$args) use ($event, $target): void {
                $target->emit($event, $args);
            });
        }
    }
}
