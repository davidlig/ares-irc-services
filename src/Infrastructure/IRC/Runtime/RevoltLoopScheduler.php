<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Runtime;

use Closure;
use Revolt\EventLoop;

final readonly class RevoltLoopScheduler implements LoopSchedulerInterface
{
    public function repeat(float $intervalSeconds, Closure $callback): string
    {
        return EventLoop::repeat($intervalSeconds, $callback);
    }

    public function delay(float $delaySeconds, Closure $callback): string
    {
        return EventLoop::delay($delaySeconds, $callback);
    }

    public function cancel(string $watcherId): void
    {
        EventLoop::cancel($watcherId);
    }
}
