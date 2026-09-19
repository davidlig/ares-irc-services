<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\InMemory;

use App\NickServ\Application\Port\Out\PendingNickProtectionRegistryInterface;
use DateTimeImmutable;

final class PendingNickProtectionRegistry implements PendingNickProtectionRegistryInterface
{
    /** @var array<string, float> Map of UID => expiry timestamp */
    private array $pending = [];

    public function schedule(string $uid, DateTimeImmutable $now, float $delaySeconds = 0.5): void
    {
        $this->pending[$uid] = (float) $now->format('U.u') + $delaySeconds;
    }

    public function cancel(string $uid): void
    {
        unset($this->pending[$uid]);
    }

    public function has(string $uid): bool
    {
        return isset($this->pending[$uid]);
    }

    /**
     * @return string[]
     */
    public function flushExpired(DateTimeImmutable $now): array
    {
        if ([] === $this->pending) {
            return [];
        }

        $timestamp = (float) $now->format('U.u');
        $expired = [];

        foreach ($this->pending as $uid => $expiresAt) {
            if ($timestamp >= $expiresAt) {
                $expired[] = $uid;
                unset($this->pending[$uid]);
            }
        }

        return $expired;
    }

    public function clear(): void
    {
        $this->pending = [];
    }
}
