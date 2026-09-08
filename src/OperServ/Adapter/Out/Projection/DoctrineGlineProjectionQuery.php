<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Projection;

use App\OperServ\Application\Port\In\GlineProjection;
use App\OperServ\Application\Port\In\GlineProjectionQuery;
use App\OperServ\Domain\Entity\Gline;
use App\OperServ\Domain\Repository\GlineRepositoryInterface;

final readonly class DoctrineGlineProjectionQuery implements GlineProjectionQuery
{
    public function __construct(private GlineRepositoryInterface $glines) {}

    public function active(): array
    {
        return array_values(array_map(
            static fn (Gline $gline): GlineProjection => new GlineProjection($gline->getMask(), $gline->getReason(), $gline->getCreatedAt(), $gline->getExpiresAt()),
            $this->glines->findActive(),
        ));
    }
}
