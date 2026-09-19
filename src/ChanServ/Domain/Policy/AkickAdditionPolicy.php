<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\Policy;

use App\ChanServ\Domain\ValueObject\AkickAdditionDecision;
use App\ChanServ\Domain\ValueObject\AkickRule;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AkickAdditionPolicy
{
    public const int MAX_ENTRIES = 100;

    public function decide(int $currentEntryCount, ?AkickRule $existingRule, DateTimeImmutable $now): AkickAdditionDecision
    {
        if (0 > $currentEntryCount) {
            throw new InvalidArgumentException('AKICK entry count cannot be negative.');
        }

        if (null !== $existingRule) {
            return $existingRule->isExpiredAt($now)
                ? AkickAdditionDecision::ReplaceExpired
                : AkickAdditionDecision::DuplicateActive;
        }

        return self::MAX_ENTRIES <= $currentEntryCount
            ? AkickAdditionDecision::LimitReached
            : AkickAdditionDecision::Add;
    }
}
