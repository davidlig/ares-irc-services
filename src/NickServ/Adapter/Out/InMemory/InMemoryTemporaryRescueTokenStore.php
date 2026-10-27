<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\InMemory;

use App\NickServ\Application\Port\Out\TemporaryRescueTokenStore;
use DateTimeImmutable;
use InvalidArgumentException;
use OverflowException;

use function count;
use function hash_equals;

final class InMemoryTemporaryRescueTokenStore implements TemporaryRescueTokenStore
{
    /** @var array<int, array{hash: string, expires: int}> */
    private array $challenges = [];

    public function __construct(private readonly int $maxEntries = 10000)
    {
        if ($maxEntries < 1) {
            throw new InvalidArgumentException('Challenge capacity must be positive.');
        }
    }

    public function replace(int $accountId, string $hash, DateTimeImmutable $expiresAt): void
    {
        if (!isset($this->challenges[$accountId]) && count($this->challenges) >= $this->maxEntries) {
            throw new OverflowException('Temporary rescue challenge capacity exhausted.');
        }
        $this->challenges[$accountId] = ['hash' => $hash, 'expires' => $expiresAt->getTimestamp()];
    }

    public function matches(int $accountId, string $hash, DateTimeImmutable $now): bool
    {
        $challenge = $this->challenges[$accountId] ?? null;

        return null !== $challenge && $challenge['expires'] > $now->getTimestamp() && hash_equals($challenge['hash'], $hash);
    }

    public function removeIfMatches(int $accountId, string $hash): void
    {
        if (isset($this->challenges[$accountId]) && hash_equals($this->challenges[$accountId]['hash'], $hash)) {
            unset($this->challenges[$accountId]);
        }
    }

    public function discard(int $accountId): void
    {
        unset($this->challenges[$accountId]);
    }

    public function prune(DateTimeImmutable $now): int
    {
        $removed = 0;
        foreach ($this->challenges as $accountId => $challenge) {
            if ($challenge['expires'] <= $now->getTimestamp()) {
                unset($this->challenges[$accountId]);
                ++$removed;
            }
        }

        return $removed;
    }
}
