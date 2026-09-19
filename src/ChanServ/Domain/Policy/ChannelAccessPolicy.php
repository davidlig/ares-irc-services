<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\Policy;

use App\ChanServ\Domain\ValueObject\AccessLevel;

final readonly class ChannelAccessPolicy
{
    public const int MAX_ENTRIES = 100;

    public function effectiveLevel(bool $identified, bool $founder, ?int $storedAccess): AccessLevel
    {
        if (!$identified) {
            return AccessLevel::fromEffective(AccessLevel::UNIDENTIFIED);
        }

        if ($founder) {
            return AccessLevel::fromEffective(AccessLevel::FOUNDER);
        }

        return AccessLevel::fromStored($storedAccess);
    }

    public function canManageLevel(AccessLevel $manager, int $storedTargetLevel): bool
    {
        return $manager->isHigherThan(AccessLevel::fromEffective($storedTargetLevel));
    }

    public function canAddEntry(int $currentEntryCount): bool
    {
        return 0 <= $currentEntryCount && self::MAX_ENTRIES > $currentEntryCount;
    }
}
