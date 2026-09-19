<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\InMemory;

use App\NickServ\Application\Port\Out\ResendThrottle;
use DateTimeImmutable;

use function sprintf;

final readonly class InMemoryResendThrottle implements ResendThrottle
{
    public function __construct(private PendingVerificationRegistry $registry) {}

    public function remainingCooldownSeconds(string $nickname, int $intervalSeconds, DateTimeImmutable $now): int
    {
        $lastResendAt = $this->registry->getLastResendAt($nickname);
        if (null === $lastResendAt || $intervalSeconds <= 0) {
            return 0;
        }

        $nextAllowedAt = $lastResendAt->modify(sprintf('+%d seconds', $intervalSeconds));
        if ($now < $nextAllowedAt) {
            return $nextAllowedAt->getTimestamp() - $now->getTimestamp();
        }

        return 0;
    }

    public function recordResend(string $nickname, DateTimeImmutable $now): void
    {
        $this->registry->recordResend($nickname, $now);
    }
}
