<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\InMemory;

use DateTimeImmutable;

use function sprintf;

/**
 * In-memory throttle for REGISTER: one attempt per host/IP per time window.
 *
 * Keyed by cloaked host (or hostname, or IP) so the limit persists across reconnects
 * and prevents mass registration from the same machine.
 */
final class RegisterThrottleRegistry
{
    /** @var array<string, DateTimeImmutable> client key (host/IP) -> last attempt time */
    private array $lastAttemptAt = [];

    public function getLastAttemptAt(string $clientKey): ?DateTimeImmutable
    {
        return $this->lastAttemptAt[$clientKey] ?? null;
    }

    public function recordAttempt(string $clientKey, DateTimeImmutable $now): void
    {
        $this->lastAttemptAt[$clientKey] = $now;
    }

    /**
     * Returns the number of seconds the client must wait before REGISTER is allowed again,
     * or 0 if allowed.
     */
    public function getRemainingCooldownSeconds(string $clientKey, int $minIntervalSeconds, DateTimeImmutable $now): int
    {
        if ($minIntervalSeconds <= 0) {
            return 0;
        }

        $last = $this->getLastAttemptAt($clientKey);

        if (null === $last) {
            return 0;
        }

        $nextAllowedAt = $last->modify(sprintf('+%d seconds', $minIntervalSeconds));

        return $now >= $nextAllowedAt ? 0 : $nextAllowedAt->getTimestamp() - $now->getTimestamp();
    }

    /**
     * Removes entries whose cooldown has already expired (no longer affect REGISTER).
     * Returns the number of keys removed. Used by maintenance to free memory.
     */
    public function pruneExpiredCooldowns(int $minIntervalSeconds, DateTimeImmutable $now): int
    {
        if ($minIntervalSeconds <= 0) {
            return 0;
        }

        $removed = 0;

        foreach ($this->lastAttemptAt as $clientKey => $lastAttempt) {
            $nextAllowedAt = $lastAttempt->modify(sprintf('+%d seconds', $minIntervalSeconds));
            if ($now >= $nextAllowedAt) {
                unset($this->lastAttemptAt[$clientKey]);
                ++$removed;
            }
        }

        return $removed;
    }
}
