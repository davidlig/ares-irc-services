<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\InMemory;

use App\NickServ\Application\Port\Out\RescueAttemptLimiter;
use DateTimeImmutable;
use InvalidArgumentException;

use function count;

final class InMemoryRescueAttemptLimiter implements RescueAttemptLimiter
{
    /** @var array<string, array{failures: list<int>, until: int}> */
    private array $buckets = [];

    private int $saturatedUntil = 0;

    public function __construct(
        private readonly int $maxAttempts = 5,
        private readonly int $windowSeconds = 900,
        private readonly int $lockoutSeconds = 600,
        private readonly int $maxBuckets = 10000,
    ) {
        if (min($maxAttempts, $windowSeconds, $lockoutSeconds, $maxBuckets) < 1) {
            throw new InvalidArgumentException('Rescue limiter configuration must be positive.');
        }
    }

    public function remainingCooldownSeconds(int $accountId, string $clientKey, DateTimeImmutable $now): int
    {
        return max(
            0,
            $this->saturatedUntil - $now->getTimestamp(),
            ($this->buckets['account:' . $accountId]['until'] ?? 0) - $now->getTimestamp(),
            ($this->buckets['client:' . $clientKey]['until'] ?? 0) - $now->getTimestamp()
        );
    }

    public function recordFailure(int $accountId, string $clientKey, DateTimeImmutable $now): void
    {
        $this->prune($now);
        if (0 < $this->remainingCooldownSeconds($accountId, $clientKey, $now)) {
            return;
        }
        $timestamp = $now->getTimestamp();
        foreach (['account:' . $accountId, 'client:' . $clientKey] as $key) {
            if (!isset($this->buckets[$key]) && count($this->buckets) >= $this->maxBuckets) {
                $this->saturatedUntil = $timestamp + $this->lockoutSeconds;

                return;
            }
            $bucket = $this->buckets[$key] ?? ['failures' => [], 'until' => 0];
            $bucket['failures'][] = $timestamp;
            if (count($bucket['failures']) >= $this->maxAttempts) {
                $bucket = ['failures' => [], 'until' => $timestamp + $this->lockoutSeconds];
            }
            $this->buckets[$key] = $bucket;
        }
    }

    public function clearFailures(int $accountId, string $clientKey): void
    {
        // Shared budgets cannot safely be reset by successful authentication.
    }

    public function prune(DateTimeImmutable $now): int
    {
        $removed = 0;
        $timestamp = $now->getTimestamp();
        foreach ($this->buckets as $key => $bucket) {
            $bucket['failures'] = array_values(array_filter($bucket['failures'], fn (int $failure): bool => $failure > $timestamp - $this->windowSeconds));
            if ([] === $bucket['failures'] && $bucket['until'] <= $timestamp) {
                unset($this->buckets[$key]);
                ++$removed;
            } else {
                $this->buckets[$key] = $bucket;
            }
        }

        return $removed;
    }
}
