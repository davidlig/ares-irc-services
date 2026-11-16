<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

/** Whether two nicknames denote the same user identity under the casemapping the IRCd negotiated. */
interface NetworkNickEquivalence
{
    public function equivalent(string $left, string $right): bool;
}
