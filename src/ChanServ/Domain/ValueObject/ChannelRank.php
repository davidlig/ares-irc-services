<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

enum ChannelRank: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Operator = 'operator';
    case HalfOperator = 'half_operator';
    case Voice = 'voice';

    public function priority(): int
    {
        return match ($this) {
            self::Owner => 5,
            self::Administrator => 4,
            self::Operator => 3,
            self::HalfOperator => 2,
            self::Voice => 1,
        };
    }

    public function isHigherThan(?self $other): bool
    {
        return $this->priority() > ($other?->priority() ?? 0);
    }

    /** @return list<self> */
    public static function highestFirst(): array
    {
        return [self::Owner, self::Administrator, self::Operator, self::HalfOperator, self::Voice];
    }
}
