<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Projection;

use App\OperServ\Adapter\Out\Projection\DoctrineGlineProjectionQuery;
use App\OperServ\Application\Port\In\GlineProjection;
use App\OperServ\Application\Port\Out\GlineEntry;
use App\OperServ\Application\Port\Out\GlineRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineGlineProjectionQuery::class)]
#[CoversClass(GlineProjection::class)]
final class DoctrineGlineProjectionQueryTest extends TestCase
{
    #[Test]
    public function projectsEveryActiveGlineWithoutExposingDomainEntities(): void
    {
        $expiresAt = new DateTimeImmutable('+1 hour');
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $gline = new GlineEntry('*@bad.example', 7, 'abuse', $createdAt, $expiresAt, 1);
        $repository = $this->createStub(GlineRepository::class);
        $repository->method('findActiveAt')->willReturn([$gline]);

        $projections = new DoctrineGlineProjectionQuery($repository)->active();
        self::assertCount(1, $projections);
        self::assertSame('*@bad.example', $projections[0]->mask);
        self::assertSame('abuse', $projections[0]->reason);
        self::assertSame($createdAt, $projections[0]->createdAt);
        self::assertSame($expiresAt, $projections[0]->expiresAt);
    }
}
