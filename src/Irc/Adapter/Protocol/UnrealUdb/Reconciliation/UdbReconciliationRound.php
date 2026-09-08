<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;

use function min;

/** Owns one authority-side reconciliation round and both deadline classes. */
final class UdbReconciliationRound
{
    private ?int $id = null;

    private ?int $inactivityDeadline = null;

    private ?int $absoluteDeadline = null;

    private bool $barrierPending = false;

    private ?int $barrierTicket = null;

    private bool $completed = false;

    /** @var array<string, true> */
    private array $requestedBlocks = [];

    public function __construct(
        private readonly int $inactivityTimeout = 60,
        private readonly int $absoluteTimeout = 300,
    ) {}

    public function start(int $id, int $now): void
    {
        $this->id = $id;
        $this->inactivityDeadline = $now + $this->inactivityTimeout;
        $this->absoluteDeadline = $now + $this->absoluteTimeout;
        $this->barrierPending = false;
        $this->barrierTicket = null;
        $this->completed = false;
        $this->requestedBlocks = [];
    }

    public function acceptRes(int $roundId, UdbBlock $block, int $now): bool
    {
        if ($this->id !== $roundId || UdbRoundTimeout::None !== $this->timeoutAt($now)) {
            return false;
        }

        $letter = $block->letter();
        if (isset($this->requestedBlocks[$letter])) {
            return false;
        }

        $this->requestedBlocks[$letter] = true;
        $this->touch($now);

        return true;
    }

    public function expectBarrier(int $ticket): void
    {
        if (null === $this->id) {
            return;
        }

        $this->barrierPending = true;
        $this->barrierTicket = $ticket;
    }

    public function acknowledgeBarrier(int $ticket, int $now): bool
    {
        if (null === $this->id
            || !$this->barrierPending
            || $this->barrierTicket !== $ticket
            || UdbRoundTimeout::None !== $this->timeoutAt($now)
        ) {
            return false;
        }

        $this->barrierPending = false;
        $this->barrierTicket = null;
        $this->touch($now);

        return true;
    }

    public function acknowledgeTransfer(int $roundId, UdbBlock $block, int $now): bool
    {
        if ($this->id !== $roundId || !isset($this->requestedBlocks[$block->letter()]) || UdbRoundTimeout::None !== $this->timeoutAt($now)) {
            return false;
        }

        $this->touch($now);

        return true;
    }

    public function completeIfSettled(bool $transfersSettled): bool
    {
        if (null === $this->id || $this->barrierPending || !$transfersSettled) {
            return false;
        }

        $this->id = null;
        $this->inactivityDeadline = null;
        $this->absoluteDeadline = null;
        $this->barrierPending = false;
        $this->barrierTicket = null;
        $this->requestedBlocks = [];
        $this->completed = true;

        return true;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function isActive(): bool
    {
        return null !== $this->id;
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function timeoutAt(int $now): UdbRoundTimeout
    {
        if (null === $this->id || null === $this->inactivityDeadline || null === $this->absoluteDeadline) {
            return UdbRoundTimeout::None;
        }

        if ($now >= $this->absoluteDeadline) {
            return UdbRoundTimeout::Absolute;
        }

        return $now >= $this->inactivityDeadline ? UdbRoundTimeout::Inactivity : UdbRoundTimeout::None;
    }

    public function nextDeadline(): ?int
    {
        if (null === $this->inactivityDeadline || null === $this->absoluteDeadline) {
            return null;
        }

        return min($this->inactivityDeadline, $this->absoluteDeadline);
    }

    public function reset(): void
    {
        $this->id = null;
        $this->inactivityDeadline = null;
        $this->absoluteDeadline = null;
        $this->barrierPending = false;
        $this->barrierTicket = null;
        $this->completed = false;
        $this->requestedBlocks = [];
    }

    private function touch(int $now): void
    {
        $this->inactivityDeadline = $now + $this->inactivityTimeout;
    }
}
