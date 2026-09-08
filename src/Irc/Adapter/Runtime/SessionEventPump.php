<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Runtime;

use Amp\DeferredFuture;
use Closure;
use LogicException;

use function array_shift;
use function count;

final class SessionEventPump
{
    /** @var list<Closure(): void> */
    private array $queue = [];

    /** @var DeferredFuture<void>|null */
    private ?DeferredFuture $deferred = null;

    private bool $running = false;

    private bool $stopped = false;

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

        $this->queue[] = $task;

        if (null !== $this->deferred) {
            $deferred = $this->deferred;
            $this->deferred = null;
            $deferred->complete();
        }
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
                while (!$this->stopped && [] !== $this->queue) {
                    $task = array_shift($this->queue);
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
}
