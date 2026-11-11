<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Adapter\Out\Persistence\Doctrine\BurstChannelActivityWriter;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(BurstChannelActivityWriter::class)]
final class BurstChannelActivityWriterTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function updatesOnlySelectedChannelActivitiesInOneBatch(): void
    {
        $old = new DateTimeImmutable('2026-01-01 00:00:00');
        $now = new DateTimeImmutable('2026-02-01 00:00:00');
        $first = RegisteredChannel::register($old, '#first', 1, 'first');
        $second = RegisteredChannel::register($old, '#second', 1, 'second');
        $this->entityManager->persist($first);
        $this->entityManager->persist($second);
        $this->flushAndClear();
        $writer = new BurstChannelActivityWriter($this->entityManager);
        $writer->touchByIds([$first->getId()], $now);
        $writer->touchByIds([], $now);
        self::assertSame($now->format('Y-m-d H:i:s'), $this->entityManager->find(RegisteredChannel::class, $first->getId())?->getLastUsedAt()?->format('Y-m-d H:i:s'));
        self::assertSame($old->format('Y-m-d H:i:s'), $this->entityManager->find(RegisteredChannel::class, $second->getId())?->getLastUsedAt()?->format('Y-m-d H:i:s'));
    }
}
