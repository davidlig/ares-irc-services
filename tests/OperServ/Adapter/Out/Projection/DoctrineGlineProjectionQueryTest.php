<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Projection;

use App\OperServ\Adapter\Out\Projection\DoctrineGlineProjectionQuery;
use App\OperServ\Application\Port\In\GlineProjection;
use App\OperServ\Domain\Entity\Gline;
use App\OperServ\Domain\Repository\GlineRepositoryInterface;
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
        $gline = Gline::create('*@bad.example', 7, 'abuse', $expiresAt);
        $repository = $this->createStub(GlineRepositoryInterface::class);
        $repository->method('findActive')->willReturn([$gline]);

        $projections = new DoctrineGlineProjectionQuery($repository)->active();
        self::assertCount(1, $projections);
        self::assertSame('*@bad.example', $projections[0]->mask);
        self::assertSame('abuse', $projections[0]->reason);
        self::assertSame($gline->getCreatedAt(), $projections[0]->createdAt);
        self::assertSame($expiresAt, $projections[0]->expiresAt);
    }
}
