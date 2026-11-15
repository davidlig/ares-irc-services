<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\Policy;

use App\ChanServ\Domain\ValueObject\AkickRule;
use Closure;

final readonly class AkickMatchPolicy
{
    /**
     * Prepares the rules of one enforcement pass: each pattern is folded once, and the first or
     * every non-expired match is then found per user mask (IRC operators are exempt).
     *
     * @param list<AkickRule>         $rules
     * @param Closure(string): string $fold  negotiated identity fold
     */
    public function prepare(array $rules, Closure $fold): PreparedAkickRules
    {
        return PreparedAkickRules::prepare($rules, $fold);
    }
}
