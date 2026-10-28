<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelAccessDoctrineRepository;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

use function array_map;

#[CoversClass(ChannelAccessDoctrineRepository::class)]
#[Group('integration')]
final class ChannelAccessDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private ChannelAccessRepositoryInterface $repository;

    #[Test]
    public function establishedAccessFiltersUnacceptedChannelsButPreservesMissingReferenceCompatibility(): void
    {
        $at = new DateTimeImmutable('-1 day');
        $channels = [
            RegisteredChannel::register($at, '#active', 8, 'active'),
            RegisteredChannel::register($at, '#suspended', 8, 'suspended'),
            RegisteredChannel::register($at, '#deleting', 8, 'deleting'),
            RegisteredChannel::createPendingRegistration($at, '#pending', 8, 'pending'),
            RegisteredChannel::createPendingRegistration($at, '#rejected', 8, 'rejected'),
        ];
        $channels[1]->suspend('test');
        $channels[2]->markPendingDeletion($at);
        $channels[4]->rejectRegistration('test', 99, 'Reviewer', $at, null);
        foreach ($channels as $index => $channel) {
            $this->entityManager->persist($channel);
            $this->entityManager->flush();
            $this->repository->save(new ChannelAccess($channel->getId(), 7, ($index + 1) * 50));
        }
        $this->repository->save(new ChannelAccess(9999, 7, 75));
        $this->entityManager->clear();
        $found = $this->repository->findEstablishedByNick(7);
        self::assertSame([150, 100, 75, 50], array_map(static fn (ChannelAccess $entry): int => $entry->getLevel(), $found));
        self::assertSame(4, $this->entityManager->getUnitOfWork()->size());
        self::assertCount(6, $this->repository->findByNick(7));
        self::assertSame([], $this->repository->findEstablishedByNick(999));
    }

    #[Test]
    public function savePersistsAccess(): void
    {
        $access = new ChannelAccess(channelId: 1, nickId: 100, level: 50);

        $this->repository->save($access);
        $this->flushAndClear();

        $found = $this->repository->findByChannelAndNick(1, 100);

        self::assertNotNull($found);
        self::assertSame(50, $found->getLevel());
    }

    #[Test]
    public function findByChannelAndNickReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByChannelAndNick(999, 999));
    }

    #[Test]
    public function findByChannelAndNickIsUnique(): void
    {
        $access = new ChannelAccess(channelId: 1, nickId: 100, level: 50);
        $this->repository->save($access);
        $this->flushAndClear();

        $access2 = new ChannelAccess(channelId: 1, nickId: 101, level: 100);
        $this->repository->save($access2);
        $this->flushAndClear();

        self::assertNotNull($this->repository->findByChannelAndNick(1, 100));
        self::assertNotNull($this->repository->findByChannelAndNick(1, 101));
        self::assertNull($this->repository->findByChannelAndNick(1, 102));
    }

    #[Test]
    public function listByChannelReturnsAccessOrderedByLevel(): void
    {
        $access1 = new ChannelAccess(channelId: 1, nickId: 100, level: 50);
        $access2 = new ChannelAccess(channelId: 1, nickId: 101, level: 200);
        $access3 = new ChannelAccess(channelId: 1, nickId: 102, level: 100);

        $this->repository->save($access1);
        $this->repository->save($access2);
        $this->repository->save($access3);
        $this->flushAndClear();

        $list = $this->repository->listByChannel(1);

        self::assertCount(3, $list);
        self::assertSame(200, $list[0]->getLevel());
        self::assertSame(100, $list[1]->getLevel());
        self::assertSame(50, $list[2]->getLevel());
    }

    #[Test]
    public function listByChannelReturnsEmptyArrayWhenNone(): void
    {
        self::assertSame([], $this->repository->listByChannel(999));
    }

    #[Test]
    public function countByChannelReturnsCorrectCount(): void
    {
        $access1 = new ChannelAccess(channelId: 1, nickId: 100, level: 50);
        $access2 = new ChannelAccess(channelId: 1, nickId: 101, level: 100);
        $access3 = new ChannelAccess(channelId: 2, nickId: 100, level: 200);

        $this->repository->save($access1);
        $this->repository->save($access2);
        $this->repository->save($access3);
        $this->flushAndClear();

        self::assertSame(2, $this->repository->countByChannel(1));
        self::assertSame(1, $this->repository->countByChannel(2));
        self::assertSame(0, $this->repository->countByChannel(999));
    }

    #[Test]
    public function findByNickReturnsAllAccessForNick(): void
    {
        $access1 = new ChannelAccess(channelId: 1, nickId: 100, level: 50);
        $access2 = new ChannelAccess(channelId: 2, nickId: 100, level: 100);
        $access3 = new ChannelAccess(channelId: 1, nickId: 101, level: 200);

        $this->repository->save($access1);
        $this->repository->save($access2);
        $this->repository->save($access3);
        $this->flushAndClear();

        $list = $this->repository->findByNick(100);

        self::assertCount(2, $list);
    }

    #[Test]
    public function removeDeletesAccess(): void
    {
        $access = new ChannelAccess(channelId: 1, nickId: 100, level: 50);
        $this->repository->save($access);
        $this->entityManager->flush();

        $this->repository->remove($access);
        $this->flushAndClear();

        self::assertNull($this->repository->findByChannelAndNick(1, 100));
    }

    #[Test]
    public function deleteByNickIdRemovesAllAccessForNick(): void
    {
        $access1 = new ChannelAccess(channelId: 1, nickId: 100, level: 50);
        $access2 = new ChannelAccess(channelId: 2, nickId: 100, level: 100);
        $access3 = new ChannelAccess(channelId: 1, nickId: 101, level: 200);

        $this->repository->save($access1);
        $this->repository->save($access2);
        $this->repository->save($access3);
        $this->flushAndClear();

        $this->repository->deleteByNickId(100);
        $this->flushAndClear();

        self::assertSame([], $this->repository->findByNick(100));
        self::assertCount(1, $this->repository->findByNick(101));
    }

    #[Test]
    public function deleteByNickIdDoesNothingWhenNoAccess(): void
    {
        $this->repository->deleteByNickId(999);
        $this->flushAndClear();

        self::assertSame([], $this->repository->findByNick(999));
    }

    #[Test]
    public function deleteByChannelIdRemovesAllAccessForChannel(): void
    {
        $access1 = new ChannelAccess(channelId: 1, nickId: 100, level: 50);
        $access2 = new ChannelAccess(channelId: 1, nickId: 101, level: 200);
        $access3 = new ChannelAccess(channelId: 2, nickId: 100, level: 100);

        $this->repository->save($access1);
        $this->repository->save($access2);
        $this->repository->save($access3);
        $this->flushAndClear();

        $deleted = $this->repository->deleteByChannelId(1);
        $this->flushAndClear();

        self::assertSame(2, $deleted);
        self::assertSame([], $this->repository->listByChannel(1));
        self::assertCount(1, $this->repository->listByChannel(2));
    }

    #[Test]
    public function deleteByChannelIdReturnsZeroWhenNoAccess(): void
    {
        $deleted = $this->repository->deleteByChannelId(999);
        $this->flushAndClear();

        self::assertSame(0, $deleted);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ChannelAccessDoctrineRepository($this->entityManager);
    }
}
