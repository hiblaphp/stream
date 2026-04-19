# Hibla Stream

**Non-blocking, event-driven streams for PHP with promise-based I/O and automatic backpressure.**

`hiblaphp/stream` provides readable, writable, and duplex stream abstractions built
on top of the Hibla event loop. Streams register I/O watchers with the event loop and
let data flow cooperatively: a read or write never blocks the thread. Backpressure is
tracked automatically: when a writable buffer fills, `write()` returns `false` and the
`drain` event signals when it is safe to continue. Pipe chains wire all of this
together without any manual coordination.

[![Latest Release](https://img.shields.io/github/release/hiblaphp/stream.svg?style=flat-square)](https://github.com/hiblaphp/stream/releases)
[![Tests](https://github.com/hiblaphp/stream/actions/workflows/test.yml/badge.svg)](https://github.com/hiblaphp/stream/actions/workflows/test.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/hiblaphp/stream.svg?style=flat-square)](https://packagist.org/packages/hiblaphp/stream)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE)

---

## Contents

**Getting started**

- [Installation](#installation)
- [Introduction](#introduction)
- [Stream Types](#stream-types)
- [Stream Events](#stream-events)
- [Creating Streams](#creating-streams)

**Stream types**

- [Readable Streams](#readable-streams)
  - [Pausing and resuming](#pausing-and-resuming)
  - [Seeking and telling](#seeking-and-telling)
- [Writable Streams](#writable-streams)
  - [Backpressure](#backpressure)
- [Piping Streams](#piping-streams)
  - [Through Streams](#through-streams)
- [Duplex Streams](#duplex-streams)
- [Composite Streams](#composite-streams)

**Promise-based API**

- [Promise-Based API](#promise-based-api)
  - [Reading data](#reading-data)
  - [Reading exact byte counts](#reading-exact-byte-counts)
  - [Writing data](#writing-data)
  - [Piping with `pipeAsync()`](#piping-with-pipeasync)
  - [Cancellation](#cancellation)

**I/O and platform**

- [Standard I/O](#standard-io)
- [Platform Notes](#platform-notes)

**Reference**

- [Stream Lifecycle and Events](#stream-lifecycle-and-events)
  - [Readable stream lifecycle](#readable-stream-lifecycle)
  - [Writable stream lifecycle](#writable-stream-lifecycle)
  - [Pipe event flow](#pipe-event-flow)
  - [ThroughStream event flow](#throughstream-event-flow)
  - [CompositeStream and DuplexResourceStream events](#compositestream-and-duplexresourcestream-events)
  - [Error event behaviour](#error-event-behaviour)
- [No-Op Behaviour](#no-op-behaviour)
- [Resource Cleanup and Destructors](#resource-cleanup-and-destructors)
- [Events Reference](#events-reference)
- [API Reference](#api-reference)

**Meta**

- [Development](#development)
- [Credits](#credits)
- [License](#license)

---

## Installation

>This package is currently in **beta**. Before installing, ensure your `composer.json`
allows beta releases:

```json
{
    "minimum-stability": "beta",
    "prefer-stable": true
}
```

```bash
composer require hiblaphp/stream
```

**Requirements:**
- PHP 8.4+ 

---

## Introduction

PHP's built-in I/O is synchronous. `fread()` blocks until data arrives. `fwrite()`
blocks until the kernel accepts the data. For a single file or socket this is fine,
but the moment you are handling multiple streams concurrently, blocking on any one of
them stalls everything else. The event loop cannot fire timers, cannot resume Fibers,
cannot process other I/O until the blocking call returns.

The solution is to hand stream I/O to the event loop entirely. Instead of calling
`fread()` blindly and waiting, you register a read watcher and supply a callback. The
event loop monitors all active streams at once (using `stream_select()` or `ext-uv`
depending on the active driver) and wakes only when data is actually available, then
calls `fread()` immediately, knowing it will return without blocking.

Hibla streams are that abstraction. A `ReadableResourceStream` registers a read
watcher with the event loop and emits `data` events when the underlying resource is
ready. A `WritableResourceStream` buffers outgoing data and registers a write watcher
to drain the buffer asynchronously without blocking. When you use the promise-based
API (`readAsync()`, `writeAsync()`, `pipeAsync()`), the stream suspends the current
Fiber at the `await()` point and resumes it exactly when the I/O completes, so the
rest of your code reads top to bottom like ordinary synchronous PHP while the event
loop handles everything underneath.

This library is the foundation that higher-level Hibla components build on.
`hiblaphp/socket` exposes TCP and UDP connections as duplex streams. `hiblaphp/dns`
reads and writes over DNS sockets using the same stream primitives. `hiblaphp/parallel`
uses the promise-based stream API and Fibers to orchestrate IPC between parent and
child processes. If you are using any of these packages you are already using
`hiblaphp/stream`. Understanding it directly gives you full visibility into how data
moves through the entire Hibla stack.

---

## Stream Types

| Class                    | Direction    | Use case                                                                                                 |
| ------------------------ | ------------ | -------------------------------------------------------------------------------------------------------- |
| `ReadableResourceStream` | Read         | Event-driven reads from any readable PHP resource                                                        |
| `WritableResourceStream` | Write        | Buffered non-blocking writes to any writable PHP resource                                                |
| `DuplexResourceStream`   | Read + Write | Single resource opened in read/write mode (e.g. `r+`)                                                    |
| `CompositeStream`        | Read + Write | Two independent streams combined into a single duplex interface                                          |
| `ThroughStream`          | Read + Write | In-line transformer: data written in is emitted out, optionally transformed                              |
| `PromiseReadableStream`  | Read         | `ReadableResourceStream` extended with `readAsync()`, `readLineAsync()`, `readAllAsync()`, `pipeAsync()` |
| `PromiseWritableStream`  | Write        | `WritableResourceStream` extended with `writeAsync()`, `writeLineAsync()`, `endAsync()`                  |

---

## Stream Events

Every stream class emits a defined set of events at precise lifecycle points.
Understanding what each event means before working with individual stream types makes
the rest of this document easier to follow.

### Readable stream events

| Event    | Arguments       | When it fires                                                    |
| -------- | --------------- | ---------------------------------------------------------------- |
| `data`   | `string $chunk` | A chunk of data is available from the resource                   |
| `end`    | —               | The stream has reached EOF and no more `data` events will follow |
| `close`  | —               | The underlying resource has been closed and freed                |
| `error`  | `\Throwable $e` | A read error occurred. The stream closes immediately after       |
| `pause`  | —               | The stream has been paused and the read watcher is removed       |
| `resume` | —               | The stream has resumed and the read watcher is re-registered     |

### Writable stream events

| Event    | Arguments       | When it fires                                                                   |
| -------- | --------------- | ------------------------------------------------------------------------------- |
| `drain`  | —               | The write buffer has dropped below the soft limit and it is safe to write again |
| `finish` | —               | `end()` was called and all buffered data has been fully flushed                 |
| `close`  | —               | The underlying resource has been closed and freed                               |
| `error`  | `\Throwable $e` | A write error occurred, or `write()` was called on a closed stream              |

### Key ordering guarantees

- On a readable stream, `end` always fires before `close` on a clean EOF. A read
  error skips `end` and goes directly to `close`.
- On a writable stream, `finish` always fires before `close` after a clean `end()`.
  Calling `close()` directly skips `finish` and discards any buffered data.
- After `close` fires, all listeners are removed. Any listener attached after
  `close` will never fire.
- `error` is always followed by `close`. The stream closes itself after emitting
  `error`. You do not need to call `close()` inside an error handler.

> **Always attach an `error` listener** on any stream you open. An unhandled `error`
> event on an `EventEmitter` propagates and may terminate your process.

For the full event sequence diagrams showing exactly when each event fires relative to
stream operations, see [Stream Lifecycle and Events](#stream-lifecycle-and-events) in
the reference section.

---

## Creating Streams

Each stream class accepts a PHP resource directly in its constructor. Resources must
be valid and opened in the appropriate mode. The constructor validates this and throws
a `StreamException` if not. Non-blocking mode is set automatically.

> **Note:** The examples throughout this README use `fopen()` to create resources for
> brevity. `fopen()` is a blocking call: it blocks the event loop for the duration of
> the file open operation. In production code running inside the event loop you should
> obtain resources through non-blocking means and pass them to the stream constructors.

```php
use Hibla\Stream\ReadableResourceStream;
use Hibla\Stream\WritableResourceStream;
use Hibla\Stream\DuplexResourceStream;
use Hibla\Stream\PromiseReadableStream;
use Hibla\Stream\PromiseWritableStream;
use Hibla\Stream\CompositeStream;
use Hibla\Stream\ThroughStream;

$readable  = new ReadableResourceStream(fopen('/path/to/input.log', 'rb'));
$writable  = new WritableResourceStream(fopen('/path/to/output.log', 'wb'));
$duplex    = new DuplexResourceStream(fopen('/path/to/data.bin', 'r+b'));
$composite = new CompositeStream($readable, $writable);
$through   = new ThroughStream(fn(string $data) => strtoupper($data));

// Promise-based variants
$readable = new PromiseReadableStream(fopen('/path/to/input.log', 'rb'));
$writable = new PromiseWritableStream(fopen('/path/to/output.log', 'wb'));

// Named constructor alternative for promise streams
$readable = PromiseReadableStream::fromResource(fopen('/path/to/input.log', 'rb'));
$writable = PromiseWritableStream::fromResource(fopen('/path/to/output.log', 'wb'));
```

A `Stream` static factory is also available as a convenience shortcut for common
cases like opening files and wrapping standard I/O handles:

```php
use Hibla\Stream\Stream;

$readable = Stream::readableFile('/path/to/input.log');
$writable = Stream::writableFile('/path/to/output.log');
$stdin    = Stream::stdin();
$stdout   = Stream::stdout();
```

---

## Readable Streams

`ReadableResourceStream` starts paused by default. No data events fire, no bytes are
read from the resource, and no event loop watcher is registered until you explicitly
call `resume()` or attach a `data` listener. The pause-by-default design gives you
time to attach all your event listeners, wire up error handlers, and set up pipe
destinations before any data starts flowing.

```php
use Hibla\Stream\ReadableResourceStream;

$stream = new ReadableResourceStream(fopen('/var/log/app.log', 'rb'));

// Attach all listeners first — no data flows yet
$stream->on('data', function (string $chunk) {
    echo $chunk;
});

$stream->on('end', function () {
    echo "Stream fully consumed\n";
});

$stream->on('error', function (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
});

// Start the flow only after all listeners are in place
$stream->resume();
```

> **If no data seems to arrive**, the most likely cause is a missing `resume()` call.
> The stream is paused and waiting for you to start it. Alternatively, switch to the
> promise-based API which handles `resume()` internally.

### Pausing and resuming

Calling `pause()` stops `data` events from firing and removes the event loop watcher,
so the stream truly idles between reads rather than spinning. Both `pause()` and
`resume()` are idempotent: calling them on an already-paused or already-flowing
stream is a no-op.

```php
$stream->on('data', function (string $chunk) use ($stream) {
    $stream->pause();
    processChunk($chunk);
    $stream->resume();
});
```

### Seeking and telling

`ReadableResourceStream` exposes `seek()` and `tell()` for seekable resources such as
files. `seek()` repositions the internal pointer, clears the read-ahead buffer, and
resets the EOF flag, meaning data will flow again from the new position even if the
stream had previously reached the end.

```php
$stream = new ReadableResourceStream(fopen('/tmp/data.bin', 'rb'));

$stream->on('data', function (string $chunk) use ($stream) {
    echo "Read: " . strlen($chunk) . " bytes\n";
    $stream->pause();
});

$stream->resume();

// Rewind to the beginning and read again
$stream->seek(0);
$stream->resume();
```

`seek()` returns `false` on non-seekable resources (pipes, sockets, and STDIN)
without throwing. Always check the return value if seekability matters:

```php
// WRONG — seek() on a pipe silently returns false
$stream = new ReadableResourceStream(STDIN);
$stream->seek(0); // false — silently ignored

// CORRECT
if ($stream->seek(0) === false) {
    // resource is non-seekable — handle accordingly
}
```

`seek()` throws a `StreamException` in two cases: when the stream is closed, and when
the underlying resource is no longer valid. `tell()` follows the same contract.

```
seek() return value
───────────────────────────────────────────────────
true            seek succeeded, buffer cleared, EOF reset
false           resource is non-seekable (pipe, socket, STDIN)
StreamException stream is closed or resource is invalid
```

```php
$position = $stream->tell();
$stream->seek(512, SEEK_SET);   // seek to byte 512
$stream->seek(0, SEEK_END);     // seek to end of file
$stream->seek(-128, SEEK_CUR);  // seek relative to current position
```

> **Note:** `seek()` discards any internally buffered data. After a seek the buffer is
> stale and continuing from it would produce incorrect data, so it is always cleared.

---

## Writable Streams

`WritableResourceStream` accepts data via `write()`, buffers it internally, and
registers a write watcher with the event loop to drain the buffer non-blocking. Unlike
readable streams, no `resume()` is needed: `write()` can be called immediately after
construction. `end()` signals that no more data will be written. The buffer drains,
then `finish` fires, then the stream closes.

`end()` and `close()` are both idempotent: calling them multiple times will not throw
or emit duplicate events. This makes it safe to call them defensively without checking
`isWritable()` first.

Calling `write()` on a closed stream emits an `error` event and returns `false` rather
than throwing an exception. Always attach an `error` listener if there is any chance
`write()` could be called after the stream closes.

```php
use Hibla\Stream\WritableResourceStream;

$stream = new WritableResourceStream(fopen('/tmp/output.txt', 'wb'));

$stream->on('finish', fn() => echo "All data written\n");
$stream->on('error', fn(\Throwable $e) => echo "Write error: " . $e->getMessage() . "\n");

$stream->write("First line\n");
$stream->write("Second line\n");
$stream->end("Final line\n");
```

### Backpressure

When a writable stream's internal buffer exceeds its soft limit, `write()` returns
`false`. This is the backpressure signal: stop writing and wait for the `drain` event
before continuing.

```
Consumer                 WritableResourceStream            Kernel buffer
   │                            │                               │
   │  write("chunk 1")          │                               │
   ├──────────────────────────► │  buffer: 10 KB (< 64 KB)     │
   │  ◄── true (keep going)     │                               │
   │                            │                               │
   │  write("chunk 2")          │                               │
   ├──────────────────────────► │  buffer: 70 KB (>= 64 KB)    │
   │  ◄── FALSE ◄───────────────│  STOP WRITING                 │
   │                            │                               │
   │  [wait for drain]          │ ─────────────────────────────►│ fwrite()
   │                            │  buffer: 15 KB (< 64 KB)      │
   │  ◄──── emit('drain') ◄─────│  RESUME                       │
```

```php
use Hibla\Stream\WritableResourceStream;

$socket   = stream_socket_client('tcp://example.com:9000');
$writable = new WritableResourceStream($socket, softLimit: 65536);

function pump(string $data, WritableResourceStream $writable): void
{
    $canContinue = $writable->write($data);

    if ($canContinue === false) {
        $writable->once('drain', function () use ($writable) {
            pump(getNextChunk(), $writable);
        });
    }
}
```

`pipe()` and `pipeAsync()` handle all of this automatically. Manual backpressure
management is only needed when calling `write()` directly.

---

## Piping Streams

`pipe()` wires a readable stream to a writable stream and handles backpressure
automatically. When the writable buffer fills, the readable is paused. When the
writable drains, the readable resumes. The destination stream is returned for
chaining.

```php
use Hibla\Stream\ReadableResourceStream;
use Hibla\Stream\WritableResourceStream;

$source      = new ReadableResourceStream(fopen('/tmp/input.bin', 'rb'));
$destination = new WritableResourceStream(fopen('/tmp/output.bin', 'wb'));

$destination->on('finish', fn() => echo "Transfer complete\n");

// pipe() calls resume() internally — no need to call it yourself
$source->pipe($destination);
```

Pipe chains are composable: each `pipe()` call returns the destination:

```php
use Hibla\Stream\ThroughStream;

$source
    ->pipe(new ThroughStream(fn($d) => gzencode($d)))
    ->pipe(new WritableResourceStream(fopen('/tmp/compressed.gz', 'wb')));
```

Pass `['end' => false]` to keep the destination open after the source ends. This is
useful when piping multiple sources to the same destination sequentially:

```php
// Source A's 'end' will NOT call dest->end()
$sourceA->pipe($dest, ['end' => false]);
$sourceA->on('end', function () use ($sourceB, $dest) {
    $sourceB->pipe($dest); // this one WILL close dest when finished
});
```

Calling `pipe()` on a non-readable source or a non-writable destination is a no-op:
no error is thrown, making it safe to call defensively in cleanup paths.

### Through Streams

`ThroughStream` is a duplex stream that sits in the middle of a pipe chain. Data
written to it is emitted as `data` events on its readable side, optionally transformed
by a callable. Without a transformer it acts as a transparent passthrough.

```php
use Hibla\Stream\ThroughStream;

// Transform: compress mid-pipe
$source
    ->pipe(new ThroughStream(fn(string $data) => gzencode($data)))
    ->pipe($destination);

// Spy: inspect data mid-pipe without modifying it
$spy = new ThroughStream(function (string $data) {
    fwrite(STDERR, sprintf("[spy] %d bytes\n", strlen($data)));
    return $data; // must return data to pass it through
});

$source->pipe($spy)->pipe($destination);
```

`write()` on a closed `ThroughStream` emits an `error` event and returns `false`.
`end()` and `close()` on an already-closed stream are no-ops. If the transformer
throws, the `error` event fires and the stream closes.

---

## Duplex Streams

`DuplexResourceStream` wraps a single resource opened in read/write mode (such as a
TCP socket or a file opened with `r+`). It presents a unified duplex interface while
managing readable and writable sides on the same underlying resource internally.

Like all readable streams in Hibla, the readable side starts paused. Attach your
`data` and `error` listeners first, then call `resume()`. The writable side is always
ready to accept `write()` calls immediately.

```php
use Hibla\Stream\DuplexResourceStream;

$socket = stream_socket_client('tcp://api.example.com:80');
$duplex = new DuplexResourceStream($socket);

$duplex->on('data', function (string $response) use ($duplex) {
    echo $response;
    $duplex->close();
});

$duplex->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");

// Write is always available immediately
$duplex->write("GET / HTTP/1.0\r\nHost: api.example.com\r\n\r\n");

// Start receiving after listeners are in place
$duplex->resume();
```

The resource must be opened in a read/write mode (containing `+` in its mode string).
Passing a read-only or write-only resource throws a `StreamException`.

---

## Composite Streams

`CompositeStream` combines two independent, one-directional streams into a single
duplex interface. This is useful when your readable and writable sides are separate
resources, for example a child process's stdout and stdin.

```php
use Hibla\Stream\CompositeStream;
use Hibla\Stream\ReadableResourceStream;
use Hibla\Stream\WritableResourceStream;

$process = proc_open('ffmpeg -i pipe:0 -f mp3 pipe:1', [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
], $pipes);

$composite = new CompositeStream(
    new ReadableResourceStream($pipes[1]), // process stdout → our readable
    new WritableResourceStream($pipes[0])  // our writable → process stdin
);

// Attach listeners before resuming
$composite->on('data', fn(string $chunk) => saveChunk($chunk));
$composite->on('end', fn() => proc_close($process));

$composite->resume();
$inputStream->pipe($composite);
```

Events from each inner stream are forwarded onto the composite automatically. The
composite `close` event fires only when both inner streams have closed. Calling
`close()` on an already-closed composite is a no-op.

---

## Promise-Based API

The event-driven API (`on('data', ...)`, `pause()`, `resume()`, `drain`) is the
right tool when you need maximum throughput and full control over flow. A TCP server
handling hundreds of simultaneous connections, a proxy streaming bytes between two
sockets, or a pipeline processing a continuous feed are all cases where the
event-driven model pays off.

But not every use case needs that level of control. Reading a log file line by line,
processing a CSV upload, writing a sequence of records to a file: these are tasks
where setting up `data` listeners, managing the `end` event, and wiring `drain`
callbacks is pure boilerplate. `PromiseReadableStream` and `PromiseWritableStream`
eliminate that boilerplate. They extend their base classes with promise-returning
methods that let you express sequential I/O as straight-line code.

The promise-based API manages pausing and resuming internally. `readAsync()` resumes
the stream to fetch the next chunk and pauses it again once the chunk is delivered:
data only flows when you ask for it. When writing, `writeAsync()` waits for the
`drain` event automatically if the buffer is full. You never need to check `write()`'s
return value or attach a `drain` listener yourself.

### Reading data

```php
use Hibla\Stream\PromiseReadableStream;
use function Hibla\await;

$stream = new PromiseReadableStream(fopen('/tmp/data.txt', 'rb'));

// Read the next chunk (up to $length bytes)
$chunk = await($stream->readAsync(1024));

// Read a full line including the trailing newline character
$line = await($stream->readLineAsync());

// Read the entire stream into a single string
$contents = await($stream->readAllAsync());
```

`readAsync()`, `readLineAsync()`, and `pipeAsync()` signal end-of-stream by resolving
with `null`. Always check with a strict `!== null` guard: a truthiness check breaks
on valid data:

| Value                          | Truthiness check | `!== null` check |
| :----------------------------- | :--------------: | :--------------: |
| `"0"` — a line containing zero |   stops early    |    continues     |
| `""` — an empty string         |   stops early    |    continues     |
| `"\n"` — a blank line          |   stops early    |    continues     |
| `null` — true EOF              |      stops       |      stops       |

```php
// CORRECT — stops only on null (EOF)
while (($line = await($stream->readLineAsync())) !== null) {
    processLine(rtrim($line));
}

// WRONG — stops on any falsy chunk, including valid data like "0" or "\n"
while ($line = await($stream->readLineAsync())) {
    processLine($line);
}
```

`readAllAsync()` is the exception: it resolves with a plain string (never `null`)
because it accumulates everything and returns the complete contents in one go:

```php
$contents = await($stream->readAllAsync());
```

Both `readAllAsync()` and `readLineAsync()` accept a `$maxLength` parameter to
prevent unbounded memory usage:

```php
$contents = await($stream->readAllAsync(maxLength: 524288));  // 512 KiB limit
$line     = await($stream->readLineAsync(maxLength: 4096));   // 4 KiB per line
```

Calling `readAsync()` on a stream that has already reached EOF resolves immediately
with `null`. No event loop tick is needed.

### Reading exact byte counts

`readAsync($length)` resolves with **up to** `$length` bytes, not exactly `$length`
bytes. The `$length` argument is passed to `fread()` as the maximum read size, but
`fread()` returns whatever the OS has buffered at the moment the read watcher fires,
which may be less than requested.

This is correct for general streaming, but wrong for binary protocol parsing where
message boundaries are defined by fixed field sizes. For those cases, loop over
`readAsync()` until you have accumulated the required count:

```php
/**
 * Read exactly $length bytes from a stream.
 * Returns null if EOF is reached before $length bytes are available.
 *
 * @return string|null
 */
function readExact(PromiseReadableStream $stream, int $length): ?string
{
    $buffer    = '';
    $remaining = $length;

    while ($remaining > 0) {
        $chunk = await($stream->readAsync($remaining));

        if ($chunk === null) {
            return null; // EOF before enough bytes arrived
        }

        $buffer    .= $chunk;
        $remaining -= strlen($chunk);
    }

    return $buffer;
}
```

With that helper, binary protocol parsing is correct regardless of how the OS
delivers data:

```php
// Read a length-prefixed binary message:
// [ 4-byte uint32 length ][ N bytes payload ]

$header = readExact($stream, 4);
if ($header === null) {
    return; // clean EOF — no more messages
}

$payloadLength = unpack('N', $header)[1];
$payload       = readExact($stream, $payloadLength);

if ($payload === null) {
    throw new \RuntimeException("Truncated message: stream ended early");
}
```

Use `readLineAsync()` for text protocols, `readExact()` for binary protocols, and raw
`readAsync()` only when your processing logic is genuinely chunk-size-agnostic:
streaming file copies, proxying raw bytes, or feeding a streaming parser that handles
partial input itself.

### Writing data

```php
use Hibla\Stream\PromiseWritableStream;
use function Hibla\await;

$stream = new PromiseWritableStream(fopen('/tmp/out.txt', 'wb'));

// Resolves with the number of bytes buffered
// Backpressure is handled internally — no drain listener needed
$bytes = await($stream->writeAsync("Hello, world\n"));

// Write a line (appends "\n" automatically)
await($stream->writeLineAsync("Another line"));

// End the stream and wait for all data to flush
// Resolves only after 'finish' fires — all data is durably written
await($stream->endAsync());
```

`writeAsync('')` resolves immediately with `0` and no write is attempted.
`endAsync()` called on a stream that is already ending or already closed resolves
immediately. It is safe to call defensively.

### Piping with `pipeAsync()`

`pipeAsync()` pipes a `PromiseReadableStream` to any writable stream and resolves with
the total number of bytes transferred once the source ends. Backpressure between
source and destination is handled automatically.

```php
use Hibla\Stream\PromiseReadableStream;
use Hibla\Stream\WritableResourceStream;
use function Hibla\await;

$source = new PromiseReadableStream(fopen('/tmp/large.bin', 'rb'));
$dest   = new WritableResourceStream(fopen('/tmp/copy.bin', 'wb'));

$totalBytes = await($source->pipeAsync($dest));
echo "Transferred: $totalBytes bytes\n";
```

### Cancellation

All promise-based methods return a standard `PromiseInterface`. Cancel any
in-flight operation by calling `cancel()` on the returned promise. Cancelling detaches
all internal event listeners, pauses the stream, and cleans up pending state. No
further callbacks fire after cancellation.

```php
use Hibla\EventLoop\Loop;
use function Hibla\await;

$readable    = new PromiseReadableStream(fopen('/tmp/large.log', 'rb'));
$readPromise = $readable->readLineAsync();

$timerId = Loop::addTimer(2.0, function () use ($readPromise) {
    $readPromise->cancel();
});

try {
    $line = await($readPromise);
    Loop::cancelTimer($timerId);
} catch (\Hibla\Promise\Exceptions\CancelledException $e) {
    echo "Read cancelled — no data within 2 seconds\n";
}
```

Cancelling `pipeAsync()` stops the transfer immediately, pauses the source, and
detaches the destination listener without closing either stream:

```php
$transferPromise = $source->pipeAsync($dest);

Loop::addTimer(5.0, fn() => $transferPromise->cancel());

try {
    $totalBytes = await($transferPromise);
} catch (\Hibla\Promise\Exceptions\CancelledException $e) {
    echo "Transfer cancelled\n";
    // $source and $dest are still open — you decide what to do with them
}
```

For structured cancellation across multiple operations, use `CancellationTokenSource` from `hiblaphp/cancellation`:

```php
use Hibla\Cancellation\CancellationTokenSource;
use function Hibla\await;

$cts = new CancellationTokenSource(30.0); // 30 second hard limit

try {
    while (($line = await($stream->readLineAsync(), $cts->token)) !== null) {
        processLine(rtrim($line));
    }
} catch (\Hibla\Promise\Exceptions\CancelledException $e) {
    echo "Stream read timed out after 30 seconds\n";
}
```

---

## Standard I/O

```php
use Hibla\Stream\PromiseReadableStream;
use Hibla\Stream\WritableResourceStream;
use Hibla\Stream\CompositeStream;
use Hibla\Stream\ReadableResourceStream;
use function Hibla\await;

// Read from STDIN line by line
$stdin = new PromiseReadableStream(STDIN);

while (($line = await($stdin->readLineAsync())) !== null) {
    processLine(rtrim($line));
}

// Write to STDOUT respecting backpressure
$stdout = new WritableResourceStream(STDOUT);
$stdout->write("Hello from async PHP\n");

// Write errors to STDERR
$stderr = new WritableResourceStream(STDERR);
$stderr->write("Something went wrong\n");

// Combined interactive console — STDIN readable, STDOUT writable
$stdio = new CompositeStream(
    new ReadableResourceStream(STDIN),
    new WritableResourceStream(STDOUT)
);

$stdio->on('data', fn(string $input) => $stdio->write("Echo: $input"));
$stdio->resume();
```

---

## Platform Notes

### Windows non-blocking limitations

Non-blocking mode is set automatically on stream construction, but the stream types
that support it differ between Unix and Windows.

On **Unix and macOS**, non-blocking mode is applied to sockets, pipes, STDIO handles,
plain files, and in-memory streams (`php://memory`, `php://temp`).

On **Windows**, non-blocking mode is only applied to socket and pipe resources. Plain
files, STDIO handles, and in-memory streams are left in blocking mode because PHP's
`stream_set_blocking()` has no effect on non-socket handles on Windows. This means
that on Windows, reading from a file or writing to STDOUT through a Hibla stream will
block the event loop for the duration of the operation, exactly as a raw `fread()` or
`fwrite()` call would.

If you are building an application that must run on Windows and needs truly
non-blocking file or STDIO I/O, offload those operations to a worker process via
`hiblaphp/parallel` rather than using stream watchers directly. Socket-based streams
(TCP, UDP, Unix sockets) behave identically on all platforms.

---

## Stream Lifecycle and Events

This section documents the exact sequence of events emitted by each stream type. It is
reference material: understanding lifecycle order matters when you are implementing
custom flow control, building protocol parsers, or debugging unexpected behaviour.

### Readable stream lifecycle

A `ReadableResourceStream` always starts paused. No watcher is registered, no data
flows, and no events fire until `resume()` is called or a `data` listener is attached.

```
new ReadableResourceStream($resource)
       │
       ▼
  ┌─────────┐
  │  PAUSED │  ◄─── pause() called (or initial state)
  └────┬────┘
       │ resume()
       ▼
  ┌─────────┐
  │ FLOWING │  ◄─── read watcher registered with event loop
  └────┬────┘
       │ data arrives
       ├──────────────── emit('data', $chunk)    ← repeats each read
       │
       │ pause() called
       ├──────────────── emit('pause')
       │                 read watcher removed
       │
       │ EOF reached
       ├──────────────── emit('end')
       │                 emit('close')           ← always follows 'end'
       │                 resource closed
       │
       │ read error
       └──────────────── emit('error', $e)
                         emit('close')           ← always follows 'error'
                         resource closed
```

`end` and `close` are always emitted in that order on EOF. A read error skips `end`
and goes directly to `close`. After `close`, all listeners are removed. Any listener
attached after `close` will never fire.

```php
$stream = new ReadableResourceStream(fopen('/var/log/app.log', 'rb'));

$stream->on('data',  fn(string $chunk)  => echo $chunk);
$stream->on('end',   fn()              => echo "Done reading\n");
$stream->on('close', fn()              => echo "Stream closed\n");
$stream->on('error', fn(\Throwable $e) => echo "Error: " . $e->getMessage() . "\n");

$stream->resume();
```

### Writable stream lifecycle

A `WritableResourceStream` is ready immediately. No `resume()` is needed.

```
new WritableResourceStream($resource)
       │
       ▼
  ┌──────────┐
  │ WRITABLE │
  └────┬─────┘
       │ write($data) — data buffered, write watcher registered
       │
       │  buffer < softLimit  ──► write() returns true
       │  buffer >= softLimit ──► write() returns false
       │
       │ write watcher drains the buffer
       ├──────────────── emit('drain')
       │
       │ end() called
       ├──────────────── emit('finish')
       │                 emit('close')
       │
       │ write() on closed stream
       └──────────────── emit('error', $e)
```

```php
$stream = new WritableResourceStream(fopen('/tmp/output.log', 'wb'));

$stream->on('drain',  fn()             => echo "Drained, can write again\n");
$stream->on('finish', fn()             => echo "All data written\n");
$stream->on('close',  fn()             => echo "Stream closed\n");
$stream->on('error',  fn(\Throwable $e) => echo "Write error: " . $e->getMessage() . "\n");

$stream->write("Hello\n");
$stream->end("Goodbye\n");
```

### Pipe event flow

`pipe()` coordinates `data`, `drain`, `end`, and `close` events between source and
destination automatically:

```
ReadableResourceStream              WritableResourceStream
       │                                    │
  emit('data', $chunk) ──────────────────► write($chunk)
       │
       │             [buffer not full]
       │         write() returns true ◄─────
       │  keep flowing
       │
  emit('data', $chunk) ──────────────────► write($chunk)
       │
       │             [buffer now full]
       │         write() returns false ◄────
  pause() ◄──────────────────────────────── STOP SOURCE
       │
       │  [event loop drains the buffer]
       │                           emit('drain')
       │  ◄── resume() ────────────────────┘
       │  [flowing again]
       │
  emit('end') ───────────────────────────► end()
       │                              emit('finish')
       │                              emit('close')
```

### `ThroughStream` event flow

`ThroughStream` is both a writable (receives `write()` calls) and a readable (emits
`data` events). Unlike resource-backed streams, it has no I/O watcher and the event
loop is not involved.

```
Upstream writes          ThroughStream          Downstream reads
     │                        │                        │
     │  write($chunk)         │                        │
     ├───────────────────────►│                        │
     │                 [transform($chunk)]             │
     │                  emit('data', $result) ────────►│
     │                        │                        │
     │  end($final)           │                        │
     ├───────────────────────►│                        │
     │                  emit('data', $transformed)     │
     │                  emit('end')                    │
     │                  emit('finish')                 │
     │                  emit('close')                  │
     │                        │                        │
     │  transformer throws    │                        │
     │                  emit('error', $e)              │
     │                  emit('close')                  │
```

### `CompositeStream` and `DuplexResourceStream` events

Both are wrappers around underlying streams. Their events are forwarded from the inner
streams, not re-emitted independently.

The `close` event on a `CompositeStream` fires only when **both** inner streams have
closed. On a `DuplexResourceStream`, `close` fires as soon as either side closes
because both share the same underlying resource.

### Error event behaviour

Both readable and writable streams follow the same rule: an `error` event is always
followed by `close`. The stream closes itself after emitting `error`. You do not need
to call `close()` inside an error handler.

```php
// CORRECT — just handle the error; close fires on its own
$stream->on('error', function (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
});

$stream->on('close', function () {
    cleanupResources();
});
```

---

## No-Op Behaviour

All stream types are designed to be called defensively without needing to check state
first. Redundant calls are silently ignored: no exceptions are thrown, no duplicate
events fire.

| Call                   | Condition                        | Behaviour                                    |
| ---------------------- | -------------------------------- | -------------------------------------------- |
| `pause()`              | Stream already paused or closed  | No-op                                        |
| `resume()`             | Stream already flowing or closed | No-op                                        |
| `close()`              | Stream already closed            | No-op                                        |
| `end()`                | Stream already ending or closed  | No-op                                        |
| `write('')`            | Any writable stream              | No-op, returns `true`, no buffer interaction |
| `writeAsync('')`       | Any `PromiseWritableStream`      | Resolves immediately with `0`                |
| `endAsync()`           | Stream already ending or closed  | Resolves immediately                         |
| `readAsync()`          | Stream already at EOF            | Resolves immediately with `null`             |
| `pipe()`               | Source not readable              | No-op, returns destination unchanged         |
| `pipe()`               | Destination not writable         | Pauses source, returns destination unchanged |
| `removeReadWatcher()`  | Watcher already removed          | No-op, returns `false`                       |
| `removeWriteWatcher()` | Watcher already removed          | No-op, returns `false`                       |

The one exception is `write()` on a closed stream: this emits an `error` event and
returns `false` rather than silently succeeding, because writing to a closed stream is
almost always a logic error that should surface rather than be swallowed.

---

## Resource Cleanup and Destructors

All stream classes implement `__destruct`. If the stream has not been explicitly
closed by the time the object is garbage collected, the destructor calls `close()`
automatically to free the underlying resource. Stream resources are never silently
leaked.

However, the destructor calls `close()` directly and does not call `end()` first.
For writable streams this has an important consequence: **any data still buffered at
destruction time is discarded and the `finish` event never fires.** If you rely on
`finish` to confirm that all data has been flushed, always call `end()` or `endAsync()`
explicitly before letting the stream go out of scope.

```php
// Wrong — buffer may be discarded if $stream goes out of scope
$stream = new WritableResourceStream(fopen('/tmp/output.txt', 'wb'));
$stream->write("Important data\n");
// $stream goes out of scope — destructor calls close(), buffer is discarded

// Correct — drain the buffer before releasing the stream
$stream = new WritableResourceStream(fopen('/tmp/output.txt', 'wb'));
$stream->on('finish', fn() => echo "All data flushed\n");
$stream->write("Important data\n");
$stream->end();
```

With the promise-based API this risk is eliminated as long as you await every write
and end call to completion. By the time `endAsync()` resolves, all data is durably
written and the destructor has nothing left to discard:

```php
$stream = new PromiseWritableStream(fopen('/tmp/output.txt', 'wb'));
await($stream->writeAsync("Important data\n"));
await($stream->endAsync()); // all data flushed before this resolves
```

The destructor is a safety net for resource handles, not a substitute for explicit
lifecycle management.

---

## Events Reference

### Readable stream events

| Event    | Arguments       | Description                                                             |
| -------- | --------------- | ----------------------------------------------------------------------- |
| `data`   | `string $chunk` | Fires when a chunk of data is available                                 |
| `end`    | —               | Fires when the stream reaches EOF and no more `data` events will follow |
| `close`  | —               | Fires when the underlying resource is closed                            |
| `error`  | `\Throwable $e` | Fires on a read error. The stream closes after emitting `error`         |
| `pause`  | —               | Fires when the stream is paused                                         |
| `resume` | —               | Fires when the stream resumes                                           |

### Writable stream events

| Event    | Arguments       | Description                                                                          |
| -------- | --------------- | ------------------------------------------------------------------------------------ |
| `drain`  | —               | Fires when the write buffer drops below the soft limit and it is safe to write again |
| `finish` | —               | Fires after `end()` is called and all buffered data has been flushed                 |
| `close`  | —               | Fires when the underlying resource is closed                                         |
| `error`  | `\Throwable $e` | Fires on a write error. The stream closes after emitting `error`                     |

---

## API Reference

### `Stream` factory

| Method                                                       | Returns                  | Description                         |
| ------------------------------------------------------------ | ------------------------ | ----------------------------------- |
| `Stream::readable($resource, $chunkSize)`                    | `ReadableResourceStream` | Wrap a readable resource            |
| `Stream::writable($resource, $softLimit)`                    | `WritableResourceStream` | Wrap a writable resource            |
| `Stream::duplex($resource, $readChunkSize, $writeSoftLimit)` | `DuplexResourceStream`   | Wrap a read/write resource          |
| `Stream::composite($readable, $writable)`                    | `CompositeStream`        | Combine two streams into one duplex |
| `Stream::through(?callable $transformer)`                    | `ThroughStream`          | Create a transform stream           |
| `Stream::readableFile($path, $chunkSize)`                    | `ReadableResourceStream` | Open a file for reading             |
| `Stream::writableFile($path, $append, $softLimit)`           | `WritableResourceStream` | Open a file for writing             |
| `Stream::duplexFile($path, $readChunkSize, $writeSoftLimit)` | `DuplexResourceStream`   | Open a file for read/write          |
| `Stream::stdin($chunkSize)`                                  | `ReadableResourceStream` | STDIN as a readable stream          |
| `Stream::stdout($softLimit)`                                 | `WritableResourceStream` | STDOUT as a writable stream         |
| `Stream::stderr($softLimit)`                                 | `WritableResourceStream` | STDERR as a writable stream         |
| `Stream::stdio($readChunkSize, $writeSoftLimit)`             | `CompositeStream`        | STDIN + STDOUT as a single duplex   |

### `ReadableStreamInterface`

| Method                         | Returns                   | Description                                                       |
| ------------------------------ | ------------------------- | ----------------------------------------------------------------- |
| `pipe($destination, $options)` | `WritableStreamInterface` | Pipe to a writable stream with automatic backpressure             |
| `isReadable()`                 | `bool`                    | True if the stream is open and readable                           |
| `pause()`                      | `void`                    | Stop emitting `data` events. No-op if already paused or closed    |
| `resume()`                     | `void`                    | Resume emitting `data` events. No-op if already flowing or closed |
| `close()`                      | `void`                    | Close the stream and free the resource. No-op if already closed   |

### `ReadableResourceStream`

Extends `ReadableStreamInterface` with:

| Method                   | Returns      | Description                                                                                                                                                       |
| ------------------------ | ------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `isEof()`                | `bool`       | True if the stream has reached the end of the resource                                                                                                            |
| `isPaused()`             | `bool`       | True if the stream is currently paused                                                                                                                            |
| `seek($offset, $whence)` | `bool`       | Reposition the stream pointer. Clears internal buffer and resets EOF. Returns `false` on non-seekable resources. Throws `StreamException` if the stream is closed |
| `tell()`                 | `int\|false` | Return the current byte position. Returns `false` if undetermined. Throws `StreamException` if the stream is closed                                               |

### `WritableStreamInterface`

| Method         | Returns | Description                                                                                                  |
| -------------- | ------- | ------------------------------------------------------------------------------------------------------------ |
| `write($data)` | `bool`  | Write data. Returns `false` if the buffer is full (backpressure). Emits `error` if called on a closed stream |
| `end($data)`   | `void`  | Signal end-of-stream, optionally writing a final chunk. No-op if already ending or closed                    |
| `isWritable()` | `bool`  | True if the stream is open and writable                                                                      |
| `close()`      | `void`  | Close the stream, discarding any buffered data. No-op if already closed                                      |

### `WritableResourceStream`

Extends `WritableStreamInterface` with:

| Method       | Returns | Description                                                                          |
| ------------ | ------- | ------------------------------------------------------------------------------------ |
| `isEnding()` | `bool`  | True if `end()` has been called and the stream is draining its buffer before closing |

### `PromiseReadableStreamInterface`

| Method                              | Returns                          | Description                                                                             |
| ----------------------------------- | -------------------------------- | --------------------------------------------------------------------------------------- |
| `readAsync($length)`                | `PromiseInterface<string\|null>` | Read a chunk. Resolves with `null` at EOF. Supports cancellation                        |
| `readLineAsync($maxLength)`         | `PromiseInterface<string\|null>` | Read until `\n` or `$maxLength`. Resolves with `null` at EOF. Supports cancellation     |
| `readAllAsync($maxLength)`          | `PromiseInterface<string>`       | Read entire stream into a string. Supports cancellation                                 |
| `pipeAsync($destination, $options)` | `PromiseInterface<int>`          | Pipe to a writable stream. Resolves with total bytes transferred. Supports cancellation |

### `PromiseWritableStreamInterface`

| Method                  | Returns                  | Description                                                                                                                         |
| ----------------------- | ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------- |
| `writeAsync($data)`     | `PromiseInterface<int>`  | Write data. Waits for `drain` if buffer is full. Resolves with `0` if `$data` is empty. Supports cancellation                       |
| `writeLineAsync($data)` | `PromiseInterface<int>`  | Write data with an appended `\n`. Supports cancellation                                                                             |
| `endAsync($data)`       | `PromiseInterface<void>` | End the stream. Resolves when all buffered data is flushed. Resolves immediately if already ending or closed. Supports cancellation |

---

## Development

```bash
git clone https://github.com/hiblaphp/stream.git
cd stream
composer install
./vendor/bin/pest
./vendor/bin/phpstan analyse
```

---

## Credits

- **API Design:** Inspired by the [ReactPHP Streams](https://github.com/reactphp/stream)
  interface. If you are familiar with ReactPHP's stream API, Hibla's will feel
  immediately familiar, with the addition of native promise-based methods and
  Fiber-aware I/O.
- **Event Emitter:** Built on [evenement/evenement](https://github.com/igorw/evenement).
- **Event Loop Integration:** Powered by [hiblaphp/event-loop](https://github.com/hiblaphp/event-loop).
- **Promise Integration:** Built on [hiblaphp/promise](https://github.com/hiblaphp/promise).

---

## License

MIT License. See [LICENSE](./LICENSE) for more information.
