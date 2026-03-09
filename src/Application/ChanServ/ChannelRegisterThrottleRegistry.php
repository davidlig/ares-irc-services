<?php

declare(strict_types=1);

namespace App\Application\ChanServ;

use DateTimeImmutable;

use function sprintf;

/**
 * In-memory throttle for channel REGISTER: one registration per nick ID per time window.
 *
 * Keyed by founder nick ID so the limit persists across reconnects
 * and prevents a single nick from mass-registering channels.
 */
final class ChannelRegisterThrottleRegistry
{
    /** @var array<int, DateTimeImmutable> nick ID -> last registration time */
    private array $lastRegistrationAt = [];

    public function getLastRegistrationAt(int $nickId): ?DateTimeImmutable
    {
        return $this->lastRegistrationAt[$nickId] ?? null;
    }

    public function recordRegistration(int $nickId): void
    {
        $this->lastRegistrationAt[$nickId] = new DateTimeImmutable();
    }

    /**
     * Returns the number of seconds the nick must wait before REGISTER is allowed again,
     * or 0 if allowed.
     */
    public function getRemainingCooldownSeconds(int $nickId, int $minIntervalSeconds): int
    {
        if ($minIntervalSeconds <= 0) {
            return 0;
        }

        $last = $this->getLastRegistrationAt($nickId);
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

    /**
     * Removes entries whose cooldown has already expired.
     * Returns the number of entries removed. Used by maintenance to free memory.
     */
    public function pruneExpiredCooldowns(int $minIntervalSeconds): int
    {
        if ($minIntervalSeconds <= 0) {
            return 0;
        }

        $now = new DateTimeImmutable();
        $removed = 0;

        foreach ($this->lastRegistrationAt as $nickId => $lastRegistration) {
            $nextAllowedAt = $lastRegistration->modify(sprintf('+%d seconds', $minIntervalSeconds));
            if ($now >= $nextAllowedAt) {
                unset($this->lastRegistrationAt[$nickId]);
                ++$removed;
            }
        }

        return $removed;
    }
}
