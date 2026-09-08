<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Projection;

use App\OperServ\Application\Port\Out\ForcedVhostPolicy;
use App\OperServ\Domain\ValueObject\ForcedVhost;

final readonly class DoctrineForcedVhostPolicy implements ForcedVhostPolicy
{
    public function isValid(string $pattern): bool
    {
        return ForcedVhost::isValidPattern($pattern);
    }
}
