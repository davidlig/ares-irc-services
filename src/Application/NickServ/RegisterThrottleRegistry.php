<?php

declare(strict_types=1);

namespace App\Application\NickServ;

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

    public function recordAttempt(string $clientKey): void
    {
        $this->lastAttemptAt[$clientKey] = new DateTimeImmutable();
    }

    /**
     * Returns the number of seconds the client must wait before REGISTER is allowed again,
     * or 0 if allowed.
     */
    public function getRemainingCooldownSeconds(string $clientKey, int $minIntervalSeconds): int
    {
        if ($minIntervalSeconds <= 0) {
            return 0;
        }

        $last = $this->getLastAttemptAt($clientKey);
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
