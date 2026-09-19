<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Runtime;

use Amp\DeferredFuture;
use Closure;
use LogicException;
use SplQueue;

use function count;

final class SessionEventPump
{
    public const int MAX_QUEUE_SIZE = 2048;

    public const int PRODUCER_RESERVE = 16;

    public const int READER_HIGH_WATER = self::MAX_QUEUE_SIZE - self::PRODUCER_RESERVE;

    public const int READER_LOW_WATER = 1024;

    /** @var SplQueue<Closure(): void> */
    private SplQueue $queue;

    /** @var array<string, Closure(): void> */
    private array $coalescedTasks = [];

    /** @var DeferredFuture<void>|null */
    private ?DeferredFuture $deferred = null;

    /** @var DeferredFuture<void>|null */
    private ?DeferredFuture $readerCapacity = null;

    private int $peakQueueSize = 0;

    private bool $running = false;

    private bool $stopped = false;

    public function __construct()
    {
        $this->queue = new SplQueue();
    }

    /**
     * Enqueues a task to be executed sequentially in the session's serialized execution path.
     *
     * @param Closure(): void $task
     */
    public function enqueue(Closure $task): void
    {
        if ($this->stopped) {
            return;
        }

        if (count($this->queue) >= self::MAX_QUEUE_SIZE) {
            throw new LogicException('SessionEventPump queue capacity exceeded.');
        }

        $this->queue->enqueue($task);
        $this->peakQueueSize = max($this->peakQueueSize, count($this->queue));

        if (null !== $this->deferred) {
            $deferred = $this->deferred;
            $this->deferred = null;
            $deferred->complete();
        }
    }

    /**
     * Keeps one queued task per key, replacing its callback with the latest generation.
     *
     * @param Closure(): void $task
     */
    public function enqueueCoalesced(string $key, Closure $task): void
    {
        if ($this->stopped) {
            return;
        }

        if (isset($this->coalescedTasks[$key])) {
            $this->coalescedTasks[$key] = $task;

            return;
        }

        $this->coalescedTasks[$key] = $task;
        try {
            $this->enqueue(function () use ($key): void {
                $latest = $this->coalescedTasks[$key];
                unset($this->coalescedTasks[$key]);
                $latest();
            });
        } catch (LogicException $e) {
            unset($this->coalescedTasks[$key]);
            throw $e;
        }
    }

    /** Wait before and after reading a line so the transport applies backpressure. */
    public function awaitReaderCapacity(): void
    {
        if ($this->stopped) {
            return;
        }

        if (count($this->queue) < self::READER_HIGH_WATER && null === $this->readerCapacity) {
            return;
        }

        $this->readerCapacity ??= new DeferredFuture();
        $this->readerCapacity->getFuture()->await();
    }

    /**
     * Runs the pump draining loop until stopped.
     * Only ONE drain loop can be active at any time.
     */
    public function drain(): void
    {
        if ($this->running) {
            throw new LogicException('SessionEventPump is already running.');
        }

        $this->running = true;

        try {
            while (!$this->stopped) {
                while (!$this->stopped && !$this->queue->isEmpty()) {
                    $task = $this->queue->dequeue();
                    if (null !== $this->readerCapacity && count($this->queue) <= self::READER_LOW_WATER) {
                        $capacity = $this->readerCapacity;
                        $this->readerCapacity = null;
                        $capacity->complete();
                    }
                    $task();
                }

                if ($this->stopped) {
                    break;
                }

                $this->deferred = new DeferredFuture();
                $this->deferred->getFuture()->await();
            }
        } finally {
            $this->running = false;
        }
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->queue = new SplQueue();
        $this->coalescedTasks = [];
        if (null !== $this->readerCapacity) {
            $capacity = $this->readerCapacity;
            $this->readerCapacity = null;
            $capacity->complete();
        }
        if (null !== $this->deferred) {
            $deferred = $this->deferred;
            $this->deferred = null;
            $deferred->complete();
        }
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }

    public function getQueueSize(): int
    {
        return count($this->queue);
    }

    public function getPeakQueueSize(): int
    {
        return $this->peakQueueSize;
    }
}
