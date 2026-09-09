<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Projection;

use App\OperServ\Application\Port\In\GlineProjection;
use App\OperServ\Application\Port\In\GlineProjectionQuery;
use App\OperServ\Application\Port\Out\GlineEntry;
use App\OperServ\Application\Port\Out\GlineRepository;
use DateTimeImmutable;

final readonly class DoctrineGlineProjectionQuery implements GlineProjectionQuery
{
    public function __construct(private GlineRepository $glines) {}

    public function active(): array
    {
        return array_map(
            static fn (GlineEntry $gline): GlineProjection => new GlineProjection($gline->mask, $gline->reason, $gline->createdAt, $gline->expiresAt),
            $this->glines->findActiveAt(new DateTimeImmutable()),
        );
    }
}
