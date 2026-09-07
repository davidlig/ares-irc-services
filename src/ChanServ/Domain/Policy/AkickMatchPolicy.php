<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\Policy;

use App\ChanServ\Domain\ValueObject\AkickRule;
use DateTimeImmutable;

final readonly class AkickMatchPolicy
{
    /** @param list<AkickRule> $rules */
    public function firstMatch(array $rules, string $userMask, DateTimeImmutable $now, bool $ircOperator): ?AkickRule
    {
        if ($ircOperator) {
            return null;
        }

        foreach ($rules as $rule) {
            if (!$rule->isExpiredAt($now) && $rule->mask->matches($userMask)) {
                return $rule;
            }
        }

        return null;
    }
}
