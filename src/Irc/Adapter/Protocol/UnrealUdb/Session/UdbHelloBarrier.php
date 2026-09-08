<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Session;

use function array_shift;

/** Tracks FIFO HEL acknowledgements used as the ordered inventory barrier. */
final class UdbHelloBarrier
{
    /** @var list<int> */
    private array $pending = [];

    private int $sequence = 0;

    private bool $confirmed = false;

    private ?int $deadline = null;

    public function sent(int $now, int $timeout): int
    {
        $ticket = ++$this->sequence;
        $this->pending[] = $ticket;
        $this->deadline = $now + $timeout;

        return $ticket;
    }

    public function acknowledge(): ?int
    {
        if ([] === $this->pending) {
            return null;
        }

        $ticket = array_shift($this->pending);
        $this->confirmed = true;
        if ([] === $this->pending) {
            $this->deadline = null;
        }

        return $ticket;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed;
    }

    public function hasPending(): bool
    {
        return [] !== $this->pending;
    }

    public function deadline(): ?int
    {
        return $this->deadline;
    }

    public function reset(): void
    {
        $this->pending = [];
        $this->confirmed = false;
        $this->deadline = null;
    }
}
