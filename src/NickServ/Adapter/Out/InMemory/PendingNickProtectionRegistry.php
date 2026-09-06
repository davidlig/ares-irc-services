<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\InMemory;

use App\NickServ\Application\Port\Out\PendingNickProtectionRegistryInterface;

use function microtime;

final class PendingNickProtectionRegistry implements PendingNickProtectionRegistryInterface
{
    /** @var array<string, float> Map of UID => expiry timestamp */
    private array $pending = [];

    public function schedule(string $uid, float $delaySeconds = 0.5): void
    {
        $this->pending[$uid] = microtime(true) + $delaySeconds;
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
    public function flushExpired(): array
    {
        if ([] === $this->pending) {
            return [];
        }

        $now = microtime(true);
        $expired = [];

        foreach ($this->pending as $uid => $expiresAt) {
            if ($now >= $expiresAt) {
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
