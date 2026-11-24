<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\Policy;

use App\ChanServ\Domain\ValueObject\AkickRule;
use Closure;

/**
 * AKICK rules of one enforcement pass with every pattern folded once under the negotiated fold.
 * Each user mask checked against them is folded once, whatever the number of rules.
 */
final readonly class PreparedAkickRules
{
    /**
     * @param list<array{AkickRule, Closure(string): bool}> $rules
     * @param Closure(string): string                       $fold
     */
    private function __construct(private array $rules, private \Closure $fold) {}

    /**
     * @param list<AkickRule>         $rules
     * @param Closure(string): string $fold  negotiated identity fold
     */
    public static function prepare(array $rules, \Closure $fold): self
    {
        $prepared = [];
        foreach ($rules as $rule) {
            $prepared[] = [$rule, $rule->mask->matcher($fold)];
        }

        return new self($prepared, $fold);
    }

    public function firstMatch(string $userMask, \DateTimeImmutable $now, bool $ircOperator): ?AkickRule
    {
        if ($ircOperator) {
            return null;
        }

        $folded = ($this->fold)($userMask);
        foreach ($this->rules as [$rule, $matches]) {
            if (!$rule->isExpiredAt($now) && $matches($folded)) {
                return $rule;
            }
        }

        return null;
    }

    /** @return list<AkickRule> */
    public function allMatches(string $userMask, \DateTimeImmutable $now, bool $ircOperator): array
    {
        if ($ircOperator) {
            return [];
        }

        $folded = ($this->fold)($userMask);
        $matches = [];
        foreach ($this->rules as [$rule, $matcher]) {
            if (!$rule->isExpiredAt($now) && $matcher($folded)) {
                $matches[] = $rule;
            }
        }

        return $matches;
    }
}
