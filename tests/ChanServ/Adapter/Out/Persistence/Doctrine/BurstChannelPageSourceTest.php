<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Adapter\Out\Persistence\Doctrine\BurstChannelPageSource;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelAkickDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\RegisteredChannelDoctrineRepository;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelAkick;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use function array_map;
use function iterator_to_array;

#[CoversClass(BurstChannelPageSource::class)]
final class BurstChannelPageSourceTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function noChannelsProduceNoPages(): void
    {
        $source = new BurstChannelPageSource(
            new RegisteredChannelDoctrineRepository($this->entityManager),
            new ChannelAkickDoctrineRepository($this->entityManager),
            $this->entityManager,
        );
        self::assertSame([], iterator_to_array($source->pages(), false));
    }

    #[Test]
    public function pageSizeMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BurstChannelPageSource(
            new RegisteredChannelDoctrineRepository($this->entityManager),
            new ChannelAkickDoctrineRepository($this->entityManager),
            $this->entityManager,
            0,
        );
    }

    #[Test]
    public function loadsBoundedPagesWithTheirPolicyEntries(): void
    {
        $now = new DateTimeImmutable('2026-01-01 UTC');
        $first = RegisteredChannel::register($now, '#a', 1, 'A');
        $second = RegisteredChannel::register($now, '#b', 1, 'B');
        $third = RegisteredChannel::register($now, '#c', 1, 'C');
        foreach ([$first, $second, $third] as $channel) {
            $this->entityManager->persist($channel);
        }
        $this->entityManager->flush();
        $this->entityManager->persist(new ChannelAccess($first->getId(), 1, 100));
        $this->entityManager->persist(new ChannelLevel($second->getId(), ChannelLevel::KEY_NOJOIN, 100));
        $this->entityManager->persist(ChannelAkick::create($now, $third->getId(), 1, '*!*@bad.test'));
        $this->flushAndClear();

        $source = new BurstChannelPageSource(
            new RegisteredChannelDoctrineRepository($this->entityManager),
            new ChannelAkickDoctrineRepository($this->entityManager),
            $this->entityManager,
            2,
        );
        $pages = iterator_to_array($source->pages(), false);
        self::assertCount(2, $pages);
        self::assertSame(['#a', '#b'], array_map(static fn (RegisteredChannel $c): string => $c->getName(), $pages[0]->channels));
        self::assertSame(['#c'], array_map(static fn (RegisteredChannel $c): string => $c->getName(), $pages[1]->channels));
        self::assertCount(1, $pages[0]->access);
        self::assertSame(100, $pages[0]->access[0]->getLevel());
        self::assertCount(1, $pages[0]->levels);
        self::assertSame(ChannelLevel::KEY_NOJOIN, $pages[0]->levels[0]->getLevelKey());
        self::assertSame([], $pages[0]->akicks);
        self::assertCount(1, $pages[1]->akicks);
        self::assertSame('*!*@bad.test', $pages[1]->akicks[0]->getMask());
        self::assertFalse($this->entityManager->contains($pages[0]->access[0]));
        self::assertFalse($this->entityManager->contains($pages[0]->levels[0]));
        self::assertFalse($this->entityManager->contains($pages[1]->akicks[0]));
    }
}
