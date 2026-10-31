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

    /**
     * @param list<AkickRule> $rules
     *
     * @return list<AkickRule>
     */
    public function allMatches(array $rules, string $userMask, DateTimeImmutable $now, bool $ircOperator = false): array
    {
        if ($ircOperator) {
            return [];
        }

        $matches = [];
        foreach ($rules as $rule) {
            if (!$rule->isExpiredAt($now) && $rule->mask->matches($userMask)) {
                $matches[] = $rule;
            }
        }

        return $matches;
    }
}
