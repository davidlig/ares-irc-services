<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\Policy;

use App\ChanServ\Domain\ValueObject\AkickRule;
use Closure;
use DateTimeImmutable;

final readonly class AkickMatchPolicy
{
    /**
     * @param list<AkickRule>         $rules
     * @param Closure(string): string $fold  negotiated identity fold
     */
    public function firstMatch(array $rules, string $userMask, DateTimeImmutable $now, bool $ircOperator, Closure $fold): ?AkickRule
    {
        if ($ircOperator) {
            return null;
        }

        foreach ($rules as $rule) {
            if (!$rule->isExpiredAt($now) && $rule->mask->matches($userMask, $fold)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param list<AkickRule>         $rules
     * @param Closure(string): string $fold  negotiated identity fold
     *
     * @return list<AkickRule>
     */
    public function allMatches(array $rules, string $userMask, DateTimeImmutable $now, bool $ircOperator, Closure $fold): array
    {
        if ($ircOperator) {
            return [];
        }

        $matches = [];
        foreach ($rules as $rule) {
            if (!$rule->isExpiredAt($now) && $rule->mask->matches($userMask, $fold)) {
                $matches[] = $rule;
            }
        }

        return $matches;
    }
}
