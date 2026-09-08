<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Legacy;

use App\Domain\OperServ\Entity\Gline;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use App\OperServ\Adapter\Out\Legacy\LegacyGlineProjectionQuery;
use App\OperServ\Application\Port\In\GlineProjection;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LegacyGlineProjectionQuery::class)]
#[CoversClass(GlineProjection::class)]
final class LegacyGlineProjectionQueryTest extends TestCase
{
    #[Test]
    public function projectsEveryActiveGlineWithoutExposingDomainEntities(): void
    {
        $expiresAt = new DateTimeImmutable('+1 hour');
        $gline = Gline::create('*@bad.example', 7, 'abuse', $expiresAt);
        $repository = $this->createStub(GlineRepositoryInterface::class);
        $repository->method('findActive')->willReturn([$gline]);

        $projections = new LegacyGlineProjectionQuery($repository)->active();
        self::assertCount(1, $projections);
        self::assertSame('*@bad.example', $projections[0]->mask);
        self::assertSame('abuse', $projections[0]->reason);
        self::assertSame($gline->getCreatedAt(), $projections[0]->createdAt);
        self::assertSame($expiresAt, $projections[0]->expiresAt);
    }
}
