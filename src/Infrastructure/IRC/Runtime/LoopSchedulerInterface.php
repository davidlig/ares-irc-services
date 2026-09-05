<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Runtime;

use Closure;

interface LoopSchedulerInterface
{
    /**
     * @param Closure(string): void $callback
     */
    public function repeat(float $intervalSeconds, Closure $callback): string;

    /**
     * @param Closure(string): void $callback
     */
    public function delay(float $delaySeconds, Closure $callback): string;

    public function cancel(string $watcherId): void;
}
