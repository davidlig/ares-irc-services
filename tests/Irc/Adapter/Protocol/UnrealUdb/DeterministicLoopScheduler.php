<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Runtime\LoopSchedulerInterface;
use Closure;

final class DeterministicLoopScheduler implements LoopSchedulerInterface
{
    private int $sequence = 0;

    /** @var array<string, Closure> */
    private array $callbacks = [];

    /** @var array<string, Closure> */
    private array $cancelledCallbacks = [];

    public function repeat(float $intervalSeconds, Closure $callback): string
    {
        return $this->store($callback);
    }

    public function delay(float $delaySeconds, Closure $callback): string
    {
        return $this->store($callback);
    }

    public function cancel(string $watcherId): void
    {
        if (isset($this->callbacks[$watcherId])) {
            $this->cancelledCallbacks[$watcherId] = $this->callbacks[$watcherId];
        }
        unset($this->callbacks[$watcherId]);
    }

    public function runNext(): void
    {
        $id = array_key_first($this->callbacks);
        if (null === $id) {
            return;
        }

        $callback = $this->callbacks[$id];
        unset($this->callbacks[$id]);
        $callback($id);
    }

    public function runCancelled(): void
    {
        $id = array_key_first($this->cancelledCallbacks);
        if (null === $id) {
            return;
        }

        $callback = $this->cancelledCallbacks[$id];
        unset($this->cancelledCallbacks[$id]);
        $callback($id);
    }

    private function store(Closure $callback): string
    {
        $id = 'watcher-' . ++$this->sequence;
        $this->callbacks[$id] = $callback;

        return $id;
    }
}
