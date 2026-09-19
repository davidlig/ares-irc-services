<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Synchronization;

use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;

use function array_shift;
use function count;

/** Bounded durable-intent queue; reset deliberately preserves its contents. */
final class UdbMutationQueue
{
    /** @var list<UdbMutation> */
    private array $pending = [];

    public function __construct(private readonly int $limit = 1024) {}

    /** Returns true when the oldest mutation had to be dropped. */
    public function enqueue(UdbMutation $mutation): bool
    {
        $overflowed = count($this->pending) >= $this->limit;
        if ($overflowed) {
            array_shift($this->pending);
        }

        $this->pending[] = $mutation;

        return $overflowed;
    }

    /** @return list<UdbMutation> */
    public function drain(): array
    {
        $pending = $this->pending;
        $this->pending = [];

        return $pending;
    }

    /** Discards mutations already represented by a committed snapshot. */
    public function discardThrough(UdbUnsignedDecimal $watermark): void
    {
        $this->pending = array_values(array_filter(
            $this->pending,
            static fn (UdbMutation $mutation): bool => null === $mutation->sequence || 0 < $mutation->sequence->compare($watermark),
        ));
    }

    public function count(): int
    {
        return count($this->pending);
    }
}
