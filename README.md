````markdown
# Hibla Stream

**Non-blocking, event-driven streams for PHP with promise-based I/O and automatic backpressure.**

`hiblaphp/stream` provides readable, writable, and duplex stream abstractions built on top of the Hibla event loop. Streams register I/O watchers with the event loop and let data flow cooperatively — a read or write never blocks the thread. Backpressure is tracked automatically: when a writable buffer fills, `write()` returns `false` and the `drain` event signals when it is safe to continue. Pipe chains wire all of this together without any manual coordination.

[![Latest Release](https://img.shields.io/github/release/hiblaphp/stream.svg?style=flat-square)](https://github.com/hiblaphp/stream/releases)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE)

---

## Introduction

PHP's built-in I/O is synchronous. `fread()` blocks until data arrives. `fwrite()` blocks until the kernel accepts the data. For a single file or socket this is fine — but the moment you are handling multiple streams concurrently, blocking on any one of them stalls everything else. The event loop cannot fire timers, cannot resume Fibers, cannot process other I/O until the blocking call returns.

The solution is to hand stream I/O to the event loop entirely. Instead of calling `fread()` and waiting, you register a read watcher and supply a callback. The event loop calls `stream_select()` across all active streams at once, wakes only when data is actually available, and invokes your callback immediately. No polling, no blocking, no wasted cycles.

Hibla streams are that abstraction. A `ReadableResourceStream` registers a read watcher with the event loop and emits `data` events when the underlying resource is ready. A `WritableResourceStream` buffers outgoing data and registers a write watcher to drain the buffer asynchronously without blocking. When you use the promise-based API — `readAsync()`, `writeAsync()`, `pipeAsync()` — the stream suspends the current Fiber at the `await()` point and resumes it exactly when the I/O completes, so the rest of your code reads top to bottom like ordinary synchronous PHP while the event loop handles everything underneath.

---

## Contents

- [Installation](#installation)
- [Stream Types](#stream-types)
- [Creating Streams](#creating-streams)
- [Readable Streams](#readable-streams)
- [Writable Streams](#writable-streams)
- [Backpressure](#backpressure)
- [Piping Streams](#piping-streams)
- [Promise-Based API](#promise-based-api)
- [Through Streams](#through-streams)
- [Duplex Streams](#duplex-streams)
- [Composite Streams](#composite-streams)
- [Standard I/O](#standard-io)
- [Resource Cleanup and Destructors](#resource-cleanup-and-destructors)
- [No-Op Behaviour](#no-op-behaviour)
- [Platform Notes](#platform-notes)
- [Events Reference](#events-reference)
- [API Reference](#api-reference)
- [Development](#development)

---

## Installation

```bash
composer require hiblaphp/stream
```

**Requirements:**
- PHP 8.3+
- `hiblaphp/event-loop`
- `hiblaphp/promise`
- `evenement/evenement`

---

## Stream Types

| Class | Direction | Use case |
|---|---|---|
| `ReadableResourceStream` | Read | Event-driven reads from any readable PHP resource |
| `WritableResourceStream` | Write | Buffered non-blocking writes to any writable PHP resource |
| `DuplexResourceStream` | Read + Write | Single resource opened in read/write mode (e.g. `r+`) |
| `CompositeStream` | Read + Write | Two independent streams combined into a single duplex interface |
| `ThroughStream` | Read + Write | In-line transformer — data written in is emitted out, optionally transformed |
| `PromiseReadableStream` | Read | `ReadableResourceStream` extended with `readAsync()`, `readLineAsync()`, `readAllAsync()`, `pipeAsync()` |
| `PromiseWritableStream` | Write | `WritableResourceStream` extended with `writeAsync()`, `writeLineAsync()`, `endAsync()` |

---

## Creating Streams

Each stream class accepts a PHP resource directly in its constructor. Resources must
be valid and opened in the appropriate mode — the constructor validates this and throws
a `StreamException` if not. Non-blocking mode is set automatically.

> **Note:** The examples throughout this README use `fopen()` to create resources for
> brevity. `fopen()` is a blocking call — it blocks the event loop for the duration of
> the file open operation. In production code running inside the event loop you should
> obtain resources through non-blocking means (pre-opened handles, socket connections
> established asynchronously, etc.) and pass them to the stream constructors.
> The `fopen()` calls here are for demonstration purposes only.

```php
use Hibla\Stream\ReadableResourceStream;
use Hibla\Stream\WritableResourceStream;
use Hibla\Stream\DuplexResourceStream;
use Hibla\Stream\PromiseReadableStream;
use Hibla\Stream\PromiseWritableStream;
use Hibla\Stream\CompositeStream;
use Hibla\Stream\ThroughStream;

// Readable
$readable = new ReadableResourceStream(fopen('/path/to/input.log', 'rb'));

// Writable
$writable = new WritableResourceStream(fopen('/path/to/output.log', 'wb'));

// Read/write (resource must be opened in r+, w+, or a+ mode)
$duplex = new DuplexResourceStream(fopen('/path/to/data.bin', 'r+b'));

// Promise-based readable and writable
$readable = new PromiseReadableStream(fopen('/path/to/input.log', 'rb'));
$writable = new PromiseWritableStream(fopen('/path/to/output.log', 'wb'));

// Combine two separate streams into a single duplex interface
$composite = new CompositeStream($readable, $writable);

// Transform stream — optional transformer callable
$through = new ThroughStream(fn(string $data) => strtoupper($data));
```

`PromiseReadableStream` and `PromiseWritableStream` also provide a `fromResource()`
named constructor as an alternative to direct instantiation:

```php
$readable = PromiseReadableStream::fromResource(fopen('/path/to/input.log', 'rb'));
$writable = PromiseWritableStream::fromResource(fopen('/path/to/output.log', 'wb'));
```

A `Stream` static factory is also available as a convenience shortcut for common
cases like opening files and wrapping standard I/O handles. It is entirely optional —
every example in this README uses direct instantiation.

```php
use Hibla\Stream\Stream;

// Equivalent convenience shortcuts
$readable = Stream::readableFile('/path/to/input.log');
$writable = Stream::writableFile('/path/to/output.log');
$stdin    = Stream::stdin();
$stdout   = Stream::stdout();
```

---

## Readable Streams

`ReadableResourceStream` starts paused by default — this is deliberate. No `data`
events fire, no bytes are read from the resource, and no event loop watcher is
registered until you explicitly call `resume()` or attach a `data` listener. The
pause-by-default design gives you time to attach all your event listeners, wire up
error handlers, and set up pipe destinations before any data starts flowing. If the
stream started eagerly, data could arrive before your listeners were in place and
chunks would be silently lost. Once your setup is complete, call `resume()` to start
the flow — or use the promise-based API, which manages pausing and resuming
internally so you never need to call it manually.

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

> **Important:** If you schedule a stream and no data seems to arrive, the most
> likely cause is a missing `resume()` call. The stream is paused — it is working
> correctly and waiting for you to start it. Always call `resume()` after attaching
> your listeners, or switch to the promise-based API which handles this for you.

### Pausing and resuming

Calling `pause()` stops `data` events from firing. The event loop watcher is removed
until `resume()` is called, so the stream truly idles between reads rather than
spinning.

Both `pause()` and `resume()` are idempotent — calling `pause()` on an already-paused
stream or `resume()` on an already-flowing stream is a no-op. No error is thrown, no
duplicate events fire. This makes it safe to call them freely in event handlers and
pipe callbacks without tracking state yourself.

```php
$stream->on('data', function (string $chunk) use ($stream) {
    $stream->pause(); // no-op if already paused
    processChunk($chunk);
    $stream->resume(); // no-op if already flowing
});
```

### Seeking and telling

`ReadableResourceStream` exposes `seek()` and `tell()` for seekable resources such as
files. `seek()` repositions the internal pointer, clears the read-ahead buffer, and
resets the EOF flag — meaning data will flow again from the new position even if the
stream had previously reached the end.

```php
use Hibla\Stream\ReadableResourceStream;

$stream = new ReadableResourceStream(fopen('/tmp/data.bin', 'rb'));

// Read the first chunk
$stream->on('data', function (string $chunk) use ($stream) {
    echo "Read: " . strlen($chunk) . " bytes\n";
    $stream->pause();
});

$stream->resume();

// Later — rewind to the beginning and read again
// seek() clears the internal buffer and resets EOF automatically
$stream->seek(0);
$stream->resume();
```

`tell()` returns the current byte position of the underlying resource, or `false` if
the position cannot be determined. Both methods throw a `StreamException` if the
stream has been closed or the resource is no longer valid. `seek()` returns `false`
on non-seekable resources (sockets, pipes, STDIN) without throwing — check the return
value if your code needs to handle both seekable and non-seekable streams generically.

```php
$position = $stream->tell();                  // int|false
$seeked   = $stream->seek(512, SEEK_SET);     // seek to byte 512
$seeked   = $stream->seek(0, SEEK_END);       // seek to end of file
$seeked   = $stream->seek(-128, SEEK_CUR);    // seek relative to current position
```

> **Note:** `seek()` and `tell()` operate on the underlying PHP resource pointer.
> If the stream has buffered data internally — for example, a partial chunk read
> ahead of a `readLineAsync()` call — `seek()` discards that buffer. This is
> intentional: after a seek the buffer is stale and continuing from it would
> produce incorrect data.

---

## Writable Streams

`WritableResourceStream` accepts data via `write()`, buffers it internally, and
registers a write watcher with the event loop to drain the buffer non-blocking.
Unlike readable streams, the writable side is always ready to accept `write()` calls
immediately — no `resume()` is needed. `end()` signals that no more data will be
written — the buffer drains, then `finish` fires, then the stream closes.

`end()` is idempotent — calling it on a stream that is already ending or already
closed is a no-op. `close()` is likewise idempotent — calling it multiple times
will not throw or emit duplicate `close` events. This makes it safe to call
`end()` or `close()` defensively without needing to check `isWritable()` or
`isEnding()` first.

Calling `write()` on a closed stream emits an `error` event and returns `false`
rather than throwing an exception. Always attach an `error` listener if there is any
chance `write()` could be called after the stream closes — an unhandled `error` event
will propagate and may terminate your process.

```php
use Hibla\Stream\WritableResourceStream;

$stream = new WritableResourceStream(fopen('/tmp/output.txt', 'wb'));

// Write is available immediately — no resume() needed
$stream->write("First line\n");
$stream->write("Second line\n");
$stream->end("Final line\n");

// Calling end() again here is a no-op — safe to do defensively
$stream->end();

$stream->on('finish', function () {
    echo "All data written\n";
});

$stream->on('error', function (\Throwable $e) {
    echo "Write error: " . $e->getMessage() . "\n";
});
```

---

## Backpressure

When a writable stream's internal buffer exceeds its soft limit, `write()` returns
`false`. This is the backpressure signal — you should stop writing and wait for the
`drain` event before continuing.

```php
use Hibla\Stream\WritableResourceStream;

$socket   = stream_socket_client('tcp://example.com:9000');
$writable = new WritableResourceStream($socket, softLimit: 65536);

function pump(string $data, WritableResourceStream $writable): void
{
    $feedMore = $writable->write($data);

    if ($feedMore === false) {
        // Buffer is full — wait for drain before sending more
        $writable->once('drain', function () use ($writable) {
            pump(getNextChunk(), $writable);
        });
    }
}
```

The `pipe()` and `pipeAsync()` methods handle this automatically — you only need to
manage backpressure manually when writing directly.

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

// pipe() calls resume() internally — no need to call it yourself
$source->pipe($destination);
```

Calling `pipe()` on a stream that is not readable is a no-op — the destination is
returned unchanged and the source is left as-is. Calling `pipe()` on a readable
source pointed at a destination that is not writable pauses the source and returns
the destination unchanged. In both cases no error is thrown, making it safe to call
`pipe()` defensively in cleanup paths.

Pass `['end' => false]` to keep the destination open after the source ends — useful
when piping multiple sources to the same destination sequentially:

```php
$source->pipe($destination, ['end' => false]);
```

Pipe chains are composable — the return value is the destination stream:

```php
use Hibla\Stream\ThroughStream;
use Hibla\Stream\WritableResourceStream;

$source
    ->pipe(new ThroughStream(fn($d) => gzencode($d)))
    ->pipe(new WritableResourceStream(fopen('/tmp/compressed.gz', 'wb')));
```

---

## Promise-Based API

The event-driven API — `on('data', ...)`, `pause()`, `resume()`, `drain` — is the
right tool when you need maximum throughput and full control over flow. A TCP server
handling hundreds of simultaneous connections, a proxy streaming bytes between two
sockets, or a pipeline processing a continuous feed are all cases where the
event-driven model pays off: you wire up the listeners once and let the event loop
drive everything at full speed.

But not every use case needs that level of control. Reading a log file line by line,
processing a CSV upload, writing a sequence of records to a file — these are
tasks where setting up `data` listeners, managing the `end` event, tracking a buffer,
and wiring `drain` callbacks is pure boilerplate. The logic you actually care about is
buried under the ceremony of the event model.

`PromiseReadableStream` and `PromiseWritableStream` exist to eliminate that boilerplate.
They extend their base classes with promise-returning methods that let you express
sequential I/O as straight-line code. Backpressure, pausing, resuming, and `drain`
handling are all managed internally — you call `readAsync()` and get the next chunk,
you call `writeAsync()` and it resolves when the data is safely buffered. No listeners
to attach, no state to track, no events to coordinate.

The promise-based API manages pausing and resuming internally. You never need to call
`resume()` manually — calling `readAsync()` resumes the stream to fetch the next chunk
and pauses it again once the chunk is delivered. This gives you precise control over
timing: data only flows when you ask for it, and the stream idles between reads without
spinning or buffering data you have not asked for yet. When writing, `writeAsync()`
waits for the `drain` event automatically if the buffer is full — you never need to
check the return value of `write()` or attach a `drain` listener yourself.

Calling `readAsync()` on a stream that has already reached EOF resolves immediately
with `null` — no suspension, no event loop tick. Calling `writeAsync('')` resolves
immediately with `0`. These fast-path no-ops mean you can call the promise API
unconditionally in loops without worrying about unnecessary Fiber overhead on
boundary conditions.

```php
use Hibla\Stream\PromiseReadableStream;
use function Hibla\await;

$stream = new PromiseReadableStream(fopen('/tmp/data.txt', 'rb'));

// No resume() needed — readAsync() handles it internally
// The stream only reads when you ask for the next chunk
$chunk = await($stream->readAsync(1024));

// Read a full line (including the newline character)
$line = await($stream->readLineAsync());

// Read the entire stream into a string
$contents = await($stream->readAllAsync());
```

```php
use Hibla\Stream\PromiseWritableStream;
use function Hibla\await;

$stream = new PromiseWritableStream(fopen('/tmp/out.txt', 'wb'));

// Write a chunk — resolves with the number of bytes buffered
// Backpressure is handled internally — no drain listener needed
$bytes = await($stream->writeAsync("Hello, world\n"));

// writeAsync('') is a no-op — resolves immediately with 0
$bytes = await($stream->writeAsync('')); // 0, no write attempted

// Write a line (appends "\n" automatically)
await($stream->writeLineAsync("Another line"));

// End the stream and wait for all data to flush
await($stream->endAsync());

// Calling endAsync() again is a no-op — resolves immediately
await($stream->endAsync());
```

### `pipeAsync()` — promise-based piping with bytes transferred

`pipeAsync()` pipes a `PromiseReadableStream` to any writable stream and resolves
with the total number of bytes transferred once the source ends. Backpressure between
source and destination is handled automatically — the source pauses when the
destination buffer fills and resumes when it drains.

```php
use Hibla\Stream\PromiseReadableStream;
use Hibla\Stream\WritableResourceStream;
use function Hibla\await;

$source = new PromiseReadableStream(fopen('/tmp/large.bin', 'rb'));
$dest   = new WritableResourceStream(fopen('/tmp/copy.bin', 'wb'));

$totalBytes = await($source->pipeAsync($dest));

echo "Transferred: $totalBytes bytes\n";
```

### Reading line by line

Without the promise API, reading a file line by line requires buffering incoming
`data` chunks, scanning for newlines manually, tracking partial lines across chunks,
and coordinating the `end` event to flush the final line. With `readLineAsync()` that
collapses to a simple loop:

```php
use Hibla\Stream\PromiseReadableStream;
use function Hibla\await;

$stream = new PromiseReadableStream(fopen('/var/log/app.log', 'rb'));

// readLineAsync() resumes and pauses the stream around each read automatically
while (($line = await($stream->readLineAsync())) !== null) {
    processLine(rtrim($line));
}
```

### `maxLength` safeguard

Both `readAllAsync()` and `readLineAsync()` accept a `$maxLength` parameter to
prevent unbounded memory usage. `readAllAsync()` defaults to 1 MiB.
`readLineAsync()` defaults to the stream's chunk size.

```php
// Read at most 512 KiB
$contents = await($stream->readAllAsync(maxLength: 524288));

// Read at most 4096 bytes per line
$line = await($stream->readLineAsync(maxLength: 4096));
```

### Cancellation

All promise-based methods support cancellation. Cancelling a promise returned by
`readAsync()`, `readLineAsync()`, `readAllAsync()`, `writeAsync()`, `endAsync()`, or
`pipeAsync()` detaches all internal event listeners, pauses the stream, and cleans up
any pending state. No further callbacks fire after cancellation.

This is particularly useful for `pipeAsync()` where you may want to abort a large
transfer mid-flight — cancelling the promise stops the transfer immediately, pauses
the source, and detaches the destination listener without closing either stream.

```php
use Hibla\Stream\PromiseReadableStream;
use Hibla\Stream\WritableResourceStream;
use Hibla\Promise\CancellationTokenSource;
use function Hibla\await;

$source = new PromiseReadableStream(fopen('/tmp/large.bin', 'rb'));
$dest   = new WritableResourceStream(fopen('/tmp/copy.bin', 'wb'));

$cts = new CancellationTokenSource(timeout: 5.0); // cancel after 5 seconds

try {
    $totalBytes = await($source->pipeAsync($dest), $cts->token);
    echo "Transferred: $totalBytes bytes\n";
} catch (\Hibla\Promise\Exceptions\CancelledException $e) {
    echo "Transfer cancelled\n";
    // $source and $dest are still open — you decide what to do with them
}
```

Cancelling a `readLineAsync()` or `readAllAsync()` in progress also cancels the
underlying `readAsync()` call that is currently suspended, immediately unblocking
the Fiber.

```php
use Hibla\EventLoop\Loop;
use Hibla\Promise\CancellationTokenSource;
use function Hibla\await;

$cts = new CancellationTokenSource();

// Cancel after 2 seconds if no line has arrived
Loop::addTimer(2.0, fn() => $cts->cancel());

$line = await($stream->readLineAsync(), $cts->token);
```

---

## Through Streams

`ThroughStream` is a duplex stream that sits in the middle of a pipe chain. Data
written to it is emitted as `data` events on its readable side, optionally
transformed by a callable. Without a transformer it acts as a transparent
passthrough.

```php
use Hibla\Stream\ReadableResourceStream;
use Hibla\Stream\WritableResourceStream;
use Hibla\Stream\ThroughStream;

$source      = new ReadableResourceStream(fopen('/tmp/input.txt', 'rb'));
$destination = new WritableResourceStream(fopen('/tmp/output.txt', 'wb'));

$source
    ->pipe(new ThroughStream(fn(string $data) => strtoupper($data)))
    ->pipe($destination);
```

```php
// Transparent passthrough — useful as a tap to inspect data mid-pipe
$spy = new ThroughStream(function (string $data) {
    echo "Passing through: " . strlen($data) . " bytes\n";
    return $data;
});

$source->pipe($spy)->pipe($destination);
```

`write()` on a closed `ThroughStream` emits an `error` event and returns `false`
rather than throwing. `end()` and `close()` on an already-closed stream are no-ops.
If the transformer throws, the `error` event fires and the stream closes.

---

## Duplex Streams

`DuplexResourceStream` wraps a single resource opened in read/write mode — such as a
TCP socket or a file opened with `r+`. It presents a unified duplex interface while
managing a `ReadableResourceStream` and `WritableResourceStream` on the same
underlying resource internally. Like all readable streams in Hibla, the readable side
starts paused — attach your `data` and `error` listeners first, then call `resume()`
to begin receiving data. The writable side is always ready to accept `write()` calls
immediately.

```php
use Hibla\Stream\DuplexResourceStream;

$socket = stream_socket_client('tcp://api.example.com:80');
$duplex = new DuplexResourceStream($socket);

// Attach all listeners before resuming — data will not flow until resume() is called
$duplex->on('data', function (string $response) use ($duplex) {
    echo $response;
    $duplex->close();
});

$duplex->on('error', function (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
});

// Write is always available immediately — no resume() needed for the writable side
$duplex->write("GET / HTTP/1.0\r\nHost: api.example.com\r\n\r\n");

// Start receiving — safe to call after write() since the resource is already open
$duplex->resume();
```

The resource must be opened in a read/write mode (containing `+` in its mode string).
Passing a read-only or write-only resource throws a `StreamException`.

---

## Composite Streams

`CompositeStream` combines two independent, one-directional streams into a single
duplex interface. This is useful when your readable and writable sides are separate
resources — for example, a child process's `stdout` and `stdin`. The readable side
of a composite stream follows the same pause-by-default behaviour — attach your
listeners before calling `resume()`.

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

$inputStream->pipe($composite);
```

Events from the readable side (`data`, `end`, `pause`, `resume`, `error`) and the
writable side (`drain`, `finish`, `error`) are forwarded onto the composite stream
automatically. The composite closes when both sides have closed. Calling `close()`
on an already-closed composite is a no-op.

---

## Standard I/O

```php
use Hibla\Stream\PromiseReadableStream;
use Hibla\Stream\WritableResourceStream;
use Hibla\Stream\CompositeStream;
use Hibla\Stream\ReadableResourceStream;
use function Hibla\await;

// Read from STDIN line by line (promise-based — no resume() needed)
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
// Attach listeners before resuming
$stdio = new CompositeStream(
    new ReadableResourceStream(STDIN),
    new WritableResourceStream(STDOUT)
);

$stdio->on('data', fn(string $input) => $stdio->write("Echo: $input"));
$stdio->resume();
```

---

## Resource Cleanup and Destructors

`ReadableResourceStream`, `WritableResourceStream`, `DuplexResourceStream`,
`CompositeStream`, and `ThroughStream` all implement `__destruct`. If the stream has
not been explicitly closed by the time the object is garbage collected, the destructor
calls `close()` automatically to free the underlying resource.

This means stream resources are never silently leaked — a stream that goes out of
scope without an explicit `close()` call will still have its file handle or socket
closed when PHP collects it.

However, the destructor calls `close()` directly — it does not call `end()` first.
For writable streams this has an important consequence: **any data still buffered at
destruction time is discarded and the `finish` event never fires.** If you rely on
`finish` to confirm that all data has been flushed to the underlying resource, you
must always call `end()` or `endAsync()` explicitly before letting the stream go out
of scope.

```php
use Hibla\Stream\WritableResourceStream;

// Wrong — buffer may not be flushed if $stream goes out of scope here
$stream = new WritableResourceStream(fopen('/tmp/output.txt', 'wb'));
$stream->write("Important data\n");
// $stream goes out of scope — destructor calls close(), buffer is discarded

// Correct — drain the buffer before releasing the stream
$stream = new WritableResourceStream(fopen('/tmp/output.txt', 'wb'));
$stream->write("Important data\n");
$stream->on('finish', fn() => echo "All data flushed\n");
$stream->end(); // drains the buffer, emits finish, then closes
```

With the promise-based API the lifecycle is more controlled. `writeAsync()` only
resolves once the data is safely buffered, and `endAsync()` only resolves once the
buffer has been fully drained and flushed to the underlying resource. If you always
`await` these calls to completion, the stream has already done its work by the time
it goes out of scope — the destructor calling `close()` at that point is just
cleaning up an already-finished resource, not discarding anything.

```php
use Hibla\Stream\PromiseWritableStream;
use function Hibla\await;

$stream = new PromiseWritableStream(fopen('/tmp/output.txt', 'wb'));

// writeAsync() resolves only when data is buffered
await($stream->writeAsync("Important data\n"));

// endAsync() resolves only when buffer is fully flushed
// By the time this resolves the data is durably written —
// the destructor has nothing left to discard
await($stream->endAsync());
```

The warning about silent data loss applies specifically to the event-driven API where
buffered data can exist at destruction time without any pending promise to signal it.
With the promise-based API, as long as you await every write and end call to
completion, the destructor is purely a resource handle cleanup and carries no risk of
data loss.

The destructor is a safety net for resource handles, not a substitute for explicit
lifecycle management. Always close or end streams intentionally in code paths where
the written data matters.

---

## No-Op Behaviour

All stream types are designed to be called defensively without needing to check state
first. Redundant calls are silently ignored — no exceptions are thrown, no duplicate
events fire. This makes cleanup paths, teardown logic, and pipe management
straightforward to write without guarding every call.

| Call | Condition | Behaviour |
|---|---|---|
| `pause()` | Stream already paused | No-op |
| `pause()` | Stream closed | No-op |
| `resume()` | Stream already flowing | No-op |
| `resume()` | Stream closed | No-op |
| `close()` | Stream already closed | No-op |
| `end()` | Stream already ending | No-op |
| `end()` | Stream already closed | No-op |
| `write('')` | Any writable stream | No-op — returns `true`, no buffer interaction |
| `writeAsync('')` | Any `PromiseWritableStream` | Resolves immediately with `0` |
| `endAsync()` | Stream already ending or closed | Resolves immediately |
| `readAsync()` | Stream already at EOF | Resolves immediately with `null` |
| `pipe()` | Source not readable | No-op — returns destination unchanged |
| `pipe()` | Destination not writable | Pauses source, returns destination unchanged |
| `removeReadWatcher()` | Watcher already removed | No-op — returns `false` |
| `removeWriteWatcher()` | Watcher already removed | No-op — returns `false` |

The one exception is `write()` on a closed stream — this emits an `error` event and
returns `false` rather than silently succeeding, because writing to a closed stream
is almost always a logic error that should surface rather than be swallowed.

---

## Platform Notes

### Windows non-blocking limitations

Non-blocking mode is set automatically on stream construction, but the stream types
that support it differ between Unix and Windows.

On **Unix and macOS**, non-blocking mode is applied to sockets, pipes, STDIO handles,
plain files, and in-memory streams (`php://memory`, `php://temp`).

On **Windows**, non-blocking mode is only applied to socket and pipe resources. Plain
files, STDIO handles (`STDIN`, `STDOUT`, `STDERR`), and in-memory streams are left in
blocking mode because PHP's `stream_set_blocking()` has no effect on non-socket
handles on Windows. This means that on Windows, reading from a file or writing to
STDOUT through a Hibla stream will block the event loop for the duration of the
operation — exactly as a raw `fread()` or `fwrite()` call would.

If you are building an application that must run on Windows and needs truly
non-blocking file or STDIO I/O, offload those operations to a worker process via
`hiblaphp/parallel` rather than using stream watchers directly. Socket-based streams
— TCP, UDP, Unix sockets — behave identically on all platforms.

---

## Events Reference

### Readable stream events

| Event | Arguments | Description |
|---|---|---|
| `data` | `string $chunk` | Fires when a chunk of data is available |
| `end` | — | Fires when the stream reaches EOF — no more `data` events will follow |
| `close` | — | Fires when the underlying resource is closed |
| `error` | `\Throwable $e` | Fires on a read error. The stream closes after emitting `error` |
| `pause` | — | Fires when the stream is paused |
| `resume` | — | Fires when the stream resumes |

### Writable stream events

| Event | Arguments | Description |
|---|---|---|
| `drain` | — | Fires when the write buffer drops below the soft limit — safe to write again |
| `finish` | — | Fires after `end()` is called and all buffered data has been flushed |
| `close` | — | Fires when the underlying resource is closed |
| `error` | `\Throwable $e` | Fires on a write error. The stream closes after emitting `error` |

---

## API Reference

### `Stream` factory

A static convenience wrapper around the stream constructors. Useful for quick scripts
and one-off usage — prefer direct instantiation in application code and dependency
injection contexts.

| Method | Returns | Description |
|---|---|---|
| `Stream::readable($resource, $chunkSize)` | `ReadableResourceStream` | Wrap a readable resource |
| `Stream::writable($resource, $softLimit)` | `WritableResourceStream` | Wrap a writable resource |
| `Stream::duplex($resource, $readChunkSize, $writeSoftLimit)` | `DuplexResourceStream` | Wrap a read/write resource |
| `Stream::composite($readable, $writable)` | `CompositeStream` | Combine two streams into one duplex |
| `Stream::through(?callable $transformer)` | `ThroughStream` | Create a transform stream |
| `Stream::readableFile($path, $chunkSize)` | `ReadableResourceStream` | Open a file for reading |
| `Stream::writableFile($path, $append, $softLimit)` | `WritableResourceStream` | Open a file for writing |
| `Stream::duplexFile($path, $readChunkSize, $writeSoftLimit)` | `DuplexResourceStream` | Open a file for read/write |
| `Stream::stdin($chunkSize)` | `ReadableResourceStream` | STDIN as a readable stream |
| `Stream::stdout($softLimit)` | `WritableResourceStream` | STDOUT as a writable stream |
| `Stream::stderr($softLimit)` | `WritableResourceStream` | STDERR as a writable stream |
| `Stream::stdio($readChunkSize, $writeSoftLimit)` | `CompositeStream` | STDIN + STDOUT as a single duplex |

### `ReadableStreamInterface`

| Method | Returns | Description |
|---|---|---|
| `pipe($destination, $options)` | `WritableStreamInterface` | Pipe to a writable stream with automatic backpressure |
| `isReadable()` | `bool` | True if the stream is open and readable |
| `pause()` | `void` | Stop emitting `data` events. No-op if already paused or closed |
| `resume()` | `void` | Resume emitting `data` events. No-op if already flowing or closed |
| `close()` | `void` | Close the stream and free the resource. No-op if already closed |

### `ReadableResourceStream`

Extends `ReadableStreamInterface` with the following additional methods:

| Method | Returns | Description |
|---|---|---|
| `isEof()` | `bool` | True if the stream has reached the end of the resource |
| `isPaused()` | `bool` | True if the stream is currently paused |
| `seek($offset, $whence)` | `bool` | Reposition the stream pointer. Clears internal buffer and resets EOF. Returns `false` on non-seekable resources. Throws `StreamException` if the stream is closed |
| `tell()` | `int\|false` | Return the current byte position. Returns `false` if undetermined. Throws `StreamException` if the stream is closed |

### `WritableStreamInterface`

| Method | Returns | Description |
|---|---|---|
| `write($data)` | `bool` | Write data. Returns `false` if the buffer is full (backpressure). Emits `error` if called on a closed stream |
| `end($data)` | `void` | Signal end-of-stream, optionally writing a final chunk. No-op if already ending or closed |
| `isWritable()` | `bool` | True if the stream is open and writable |
| `close()` | `void` | Close the stream, discarding any buffered data. No-op if already closed |

### `WritableResourceStream`

Extends `WritableStreamInterface` with the following additional method:

| Method | Returns | Description |
|---|---|---|
| `isEnding()` | `bool` | True if `end()` has been called and the stream is draining its buffer before closing |

### `PromiseReadableStreamInterface`

| Method | Returns | Description |
|---|---|---|
| `readAsync($length)` | `PromiseInterface<string\|null>` | Read a chunk. Resolves immediately with `null` if already at EOF. Supports cancellation |
| `readLineAsync($maxLength)` | `PromiseInterface<string\|null>` | Read until `\n` or `$maxLength`. Resolves immediately with `null` if already at EOF. Supports cancellation |
| `readAllAsync($maxLength)` | `PromiseInterface<string>` | Read entire stream into a string. Supports cancellation |
| `pipeAsync($destination, $options)` | `PromiseInterface<int>` | Pipe to a writable stream. Resolves with total bytes transferred. Backpressure handled internally. Supports cancellation |

### `PromiseWritableStreamInterface`

| Method | Returns | Description |
|---|---|---|
| `writeAsync($data)` | `PromiseInterface<int>` | Write data. Waits for `drain` automatically if buffer is full. Resolves immediately with `0` if `$data` is empty. Supports cancellation |
| `writeLineAsync($data)` | `PromiseInterface<int>` | Write data with an appended `\n`. Supports cancellation |
| `endAsync($data)` | `PromiseInterface<void>` | End the stream. Resolves when all buffered data is flushed. Resolves immediately if already ending or closed. Supports cancellation |

---

## Development

```bash
git clone https://github.com/hiblaphp/stream.git
cd stream
composer install
```

```bash
./vendor/bin/pest
```

```bash
./vendor/bin/phpstan analyse
```

---

## Credits

- **API Design:** Inspired by the [ReactPHP Streams](https://github.com/reactphp/stream) interface. If you are familiar with ReactPHP's stream API, Hibla's will feel immediately familiar — with the addition of native promise-based methods and Fiber-aware I/O.
- **Event Emitter:** Built on [evenement/evenement](https://github.com/igorw/evenement).
- **Event Loop Integration:** Powered by [hiblaphp/event-loop](https://github.com/hiblaphp/event-loop).
- **Promise Integration:** Built on [hiblaphp/promise](https://github.com/hiblaphp/promise).

---

## License

MIT License. See [LICENSE](./LICENSE) for more information.
````