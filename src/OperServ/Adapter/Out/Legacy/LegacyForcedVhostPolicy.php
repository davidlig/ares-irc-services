<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Legacy;

use App\Domain\OperServ\ValueObject\ForcedVhost;
use App\OperServ\Application\Port\Out\ForcedVhostPolicy;

final readonly class LegacyForcedVhostPolicy implements ForcedVhostPolicy
{
    public function isValid(string $pattern): bool
    {
        return ForcedVhost::isValidPattern($pattern);
    }
}
