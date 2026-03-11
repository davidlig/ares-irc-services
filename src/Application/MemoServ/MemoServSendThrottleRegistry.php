<?php

declare(strict_types=1);

namespace App\Application\MemoServ;

use DateTimeImmutable;

use function sprintf;

/**
 * In-memory throttle for MemoServ SEND: one send per sender per time window.
 * Keyed by sender UID so the limit is per connected user.
 */
final class MemoServSendThrottleRegistry
{
    /** @var array<string, DateTimeImmutable> sender UID -> last send time */
    private array $lastSendAt = [];

    public function getLastSendAt(string $senderUid): ?DateTimeImmutable
    {
        return $this->lastSendAt[$senderUid] ?? null;
    }

    public function recordSend(string $senderUid): void
    {
        $this->lastSendAt[$senderUid] = new DateTimeImmutable();
    }

    /**
     * Returns the number of seconds the sender must wait before SEND is allowed again,
     * or 0 if allowed.
     */
    public function getRemainingCooldownSeconds(string $senderUid, int $minIntervalSeconds): int
    {
        if ($minIntervalSeconds <= 0) {
            return 0;
        }

        $last = $this->getLastSendAt($senderUid);
        if (null === $last) {
            return 0;
        }

        $nextAllowedAt = $last->modify(sprintf('+%d seconds', $minIntervalSeconds));
        $now = new DateTimeImmutable();

        if ($now >= $nextAllowedAt) {
            return 0;
        }

        return $nextAllowedAt->getTimestamp() - $now->getTimestamp();
    }
}
