<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\In;

final readonly class OperatorNetworkProjection
{
    public function __construct(public int $nickId, public ?string $forcedVhost, public ?string $operclass) {}
}
