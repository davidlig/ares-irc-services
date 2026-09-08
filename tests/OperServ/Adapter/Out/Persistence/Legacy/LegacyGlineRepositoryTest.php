<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Persistence\Legacy;

use App\Domain\OperServ\Entity\Gline;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use App\OperServ\Adapter\Out\Persistence\Legacy\LegacyGlineRepository;
use App\OperServ\Application\Port\Out\GlineEntry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LegacyGlineRepository::class)]
#[CoversClass(GlineEntry::class)]
final class LegacyGlineRepositoryTest extends TestCase
{
    #[Test]
    public function translatesFindOperationsAndCounts(): void
    {
        $first = Gline::create('one@host.test', 4, 'first', new DateTimeImmutable('2026-10-01'));
        $second = Gline::create('two@host.test', null, null, null);
        $repository = $this->createMock(GlineRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('findByMask')->willReturnMap([
            ['one@host.test', $first],
            ['missing@host.test', null],
        ]);
        $repository->expects(self::once())->method('findAll')->willReturn([$first, $second]);
        $repository->expects(self::once())->method('findByMaskPattern')->with('*@host.test')->willReturn([$second]);
        $repository->expects(self::once())->method('countAll')->willReturn(2);
        $adapter = new LegacyGlineRepository($repository);

        $found = $adapter->findByMask('one@host.test');

        self::assertNotNull($found);
        self::assertSame('one@host.test', $found->mask);
        self::assertSame(4, $found->creatorAccountId);
        self::assertSame('first', $found->reason);
        self::assertTrue($found->isExpiredAt(new DateTimeImmutable('2026-10-02')));
        self::assertFalse($found->isExpiredAt(new DateTimeImmutable('2026-09-30')));
        self::assertNull($adapter->findByMask('missing@host.test'));
        self::assertSame(['one@host.test', 'two@host.test'], array_map(static fn (GlineEntry $entry): string => $entry->mask, $adapter->findAll()));
        self::assertSame(['two@host.test'], array_map(static fn (GlineEntry $entry): string => $entry->mask, $adapter->findByMaskPattern('*@host.test')));
        self::assertSame(2, $adapter->countAll());
    }

    #[Test]
    public function createsLegacyEntityWhenSaving(): void
    {
        $expiresAt = new DateTimeImmutable('2026-10-01');
        $repository = $this->createMock(GlineRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with(self::callback(
            static fn (Gline $gline): bool => 'ident@host.test' === $gline->getMask()
                && 42 === $gline->getCreatorNickId()
                && 'abuse' === $gline->getReason()
                && $expiresAt === $gline->getExpiresAt(),
        ));

        new LegacyGlineRepository($repository)->save('ident@host.test', 42, 'abuse', $expiresAt);
    }

    #[Test]
    public function removesOnlyWhenLegacyEntityStillExists(): void
    {
        $legacy = Gline::create('ident@host.test');
        $entry = new GlineEntry('ident@host.test', null, null, new DateTimeImmutable(), null);
        $repository = $this->createMock(GlineRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('findByMask')->with('ident@host.test')->willReturnOnConsecutiveCalls($legacy, null);
        $repository->expects(self::once())->method('remove')->with($legacy);
        $adapter = new LegacyGlineRepository($repository);

        $adapter->remove($entry);
        $adapter->remove($entry);
    }
}
