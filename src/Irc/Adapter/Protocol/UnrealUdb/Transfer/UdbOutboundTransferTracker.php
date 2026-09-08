<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Transfer;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;

use function min;

/** Owns pending txid correlation and timeout state for outbound snapshots. */
final class UdbOutboundTransferTracker
{
    /** @var array<string, array{txid: string, roundId: int, digest: string, inactivityDeadline: int, absoluteDeadline: int}> */
    private array $pending = [];

    public function __construct(
        private readonly int $inactivityTimeout = 60,
        private readonly int $absoluteTimeout = 300,
    ) {}

    public function track(UdbBlock $block, int $roundId, string $txid, string $digest, int $now): bool
    {
        $letter = $block->letter();
        if (isset($this->pending[$letter])) {
            return false;
        }

        $this->pending[$letter] = [
            'txid' => $txid,
            'roundId' => $roundId,
            'digest' => $digest,
            'inactivityDeadline' => $now + $this->inactivityTimeout,
            'absoluteDeadline' => $now + $this->absoluteTimeout,
        ];

        return true;
    }

    public function acknowledge(UdbFrame $frame): UdbTransferAcknowledgement
    {
        $letter = $frame->block?->letter();
        $expected = null !== $letter ? ($this->pending[$letter] ?? null) : null;
        if (null === $expected) {
            return UdbTransferAcknowledgement::Unknown;
        }

        if ($expected['roundId'] !== $frame->roundId
            || $expected['txid'] !== $frame->txid
            || $expected['digest'] !== $frame->checksum
        ) {
            return UdbTransferAcknowledgement::Mismatched;
        }

        unset($this->pending[$letter]);

        return UdbTransferAcknowledgement::Accepted;
    }

    public function has(UdbBlock $block): bool
    {
        return isset($this->pending[$block->letter()]);
    }

    public function isEmpty(): bool
    {
        return [] === $this->pending;
    }

    /** @return array{block: string, timeout: string, roundId: int}|null */
    public function firstExpired(int $now): ?array
    {
        foreach ($this->pending as $block => $transfer) {
            if ($now >= $transfer['absoluteDeadline']) {
                return ['block' => $block, 'timeout' => 'absolute', 'roundId' => $transfer['roundId']];
            }
            if ($now >= $transfer['inactivityDeadline']) {
                return ['block' => $block, 'timeout' => 'inactivity', 'roundId' => $transfer['roundId']];
            }
        }

        return null;
    }

    public function nextDeadline(): ?int
    {
        $next = null;
        foreach ($this->pending as $transfer) {
            $candidate = min($transfer['inactivityDeadline'], $transfer['absoluteDeadline']);
            if (null === $next || $candidate < $next) {
                $next = $candidate;
            }
        }

        return $next;
    }

    public function reset(): void
    {
        $this->pending = [];
    }
}
