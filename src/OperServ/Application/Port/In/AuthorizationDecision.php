<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\In;

final readonly class AuthorizationDecision
{
    private function __construct(
        public bool $granted,
        public ?AuthorizationGrant $grant,
    ) {}

    public static function grantedBy(AuthorizationGrant $grant): self
    {
        return new self(true, $grant);
    }

    public static function denied(): self
    {
        return new self(false, null);
    }
}
