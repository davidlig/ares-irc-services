<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Infrastructure\IRC\Runtime\LoopSchedulerInterface;
use Closure;

final class DeterministicLoopScheduler implements LoopSchedulerInterface
{
    private int $sequence = 0;

    /** @var array<string, Closure> */
    private array $callbacks = [];

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

    private function store(Closure $callback): string
    {
        $id = 'watcher-' . ++$this->sequence;
        $this->callbacks[$id] = $callback;

        return $id;
    }
}
