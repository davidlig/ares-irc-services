<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\ChanServ\Doctrine;

use App\Domain\ChanServ\Entity\ChannelAkick;
use App\Infrastructure\ChanServ\Doctrine\ChannelAkickDoctrineRepository;
use App\Tests\Integration\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ChannelAkickDoctrineRepository::class)]
#[Group('integration')]
final class ChannelAkickDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private ChannelAkickDoctrineRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ChannelAkickDoctrineRepository($this->entityManager);
    }

    #[Test]
    public function savePersistsAkick(): void
    {
        $akick = ChannelAkick::create(
            channelId: 1,
            creatorNickId: 100,
            mask: '*!*@test.com',
            reason: 'Spam',
            expiresAt: null
        );

        $this->repository->save($akick);
        $this->flushAndClear();

        $found = $this->repository->findById($akick->getId());

        self::assertNotNull($found);
        self::assertSame('*!*@test.com', $found->getMask());
        self::assertSame('Spam', $found->getReason());
        self::assertSame(1, $found->getChannelId());
        self::assertSame(100, $found->getCreatorNickId());
        self::assertNull($found->getExpiresAt());
    }

    #[Test]
    public function saveWithNullReason(): void
    {
        $akick = ChannelAkick::create(
            channelId: 1,
            creatorNickId: 100,
            mask: '*!*@host.com',
            reason: null,
            expiresAt: null
        );

        $this->repository->save($akick);
        $this->flushAndClear();

        $found = $this->repository->findById($akick->getId());

        self::assertNotNull($found);
        self::assertNull($found->getReason());
    }

    #[Test]
    public function saveWithExpiryDate(): void
    {
        $expiresAt = new DateTimeImmutable('+7 days');

        $akick = ChannelAkick::create(
            channelId: 1,
            creatorNickId: 100,
            mask: '*!*@temp.com',
            reason: 'Temporary ban',
            expiresAt: $expiresAt
        );

        $this->repository->save($akick);
        $this->flushAndClear();

        $found = $this->repository->findById($akick->getId());

        self::assertNotNull($found);
        self::assertNotNull($found->getExpiresAt());
    }

    #[Test]
    public function removeDeletesAkick(): void
    {
        $akick = ChannelAkick::create(
            channelId: 1,
            creatorNickId: 100,
            mask: '*!*@remove.com',
            reason: 'Test'
        );

        $this->repository->save($akick);
        $this->flushAndClear();

        $savedAkick = $this->repository->findByChannelAndMask(1, '*!*@remove.com');
        self::assertNotNull($savedAkick);

        $id = $savedAkick->getId();
        $this->repository->remove($savedAkick);
        $this->flushAndClear();

        self::assertNull($this->repository->findById($id));
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById(99999));
    }

    #[Test]
    public function listByChannelReturnsAkickOrderedByCreatedAt(): void
    {
        $akick1 = ChannelAkick::create(channelId: 1, creatorNickId: 100, mask: '*!*@first.com');
        usleep(10000);
        $akick2 = ChannelAkick::create(channelId: 1, creatorNickId: 101, mask: '*!*@second.com');
        usleep(10000);
        $akick3 = ChannelAkick::create(channelId: 1, creatorNickId: 102, mask: '*!*@third.com');

        $this->repository->save($akick1);
        $this->repository->save($akick2);
        $this->repository->save($akick3);
        $this->flushAndClear();

        $list = $this->repository->listByChannel(1);

        self::assertCount(3, $list);
        self::assertSame('*!*@first.com', $list[0]->getMask());
        self::assertSame('*!*@second.com', $list[1]->getMask());
        self::assertSame('*!*@third.com', $list[2]->getMask());
    }

    #[Test]
    public function listByChannelReturnsOnlyForSpecificChannel(): void
    {
        $akick1 = ChannelAkick::create(channelId: 1, creatorNickId: 100, mask: '*!*@chan1.com');
        $akick2 = ChannelAkick::create(channelId: 2, creatorNickId: 100, mask: '*!*@chan2.com');
        $akick3 = ChannelAkick::create(channelId: 1, creatorNickId: 101, mask: '*!*@chan1b.com');

        $this->repository->save($akick1);
        $this->repository->save($akick2);
        $this->repository->save($akick3);
        $this->flushAndClear();

        $list = $this->repository->listByChannel(1);

        self::assertCount(2, $list);
    }

    #[Test]
    public function listByChannelReturnsEmptyArrayWhenNone(): void
    {
        self::assertSame([], $this->repository->listByChannel(999));
    }

    #[Test]
    public function findByChannelAndMaskReturnsMatchingAkick(): void
    {
        $akick = ChannelAkick::create(
            channelId: 1,
            creatorNickId: 100,
            mask: '*!*@specific.com',
            reason: 'Test ban'
        );

        $this->repository->save($akick);
        $this->flushAndClear();

        $found = $this->repository->findByChannelAndMask(1, '*!*@specific.com');

        self::assertNotNull($found);
        self::assertSame('Test ban', $found->getReason());
    }

    #[Test]
    public function findByChannelAndMaskReturnsNullForWrongChannel(): void
    {
        $akick = ChannelAkick::create(
            channelId: 1,
            creatorNickId: 100,
            mask: '*!*@test.com'
        );

        $this->repository->save($akick);
        $this->flushAndClear();

        self::assertNull($this->repository->findByChannelAndMask(2, '*!*@test.com'));
    }

    #[Test]
    public function findByChannelAndMaskReturnsNullForWrongMask(): void
    {
        $akick = ChannelAkick::create(
            channelId: 1,
            creatorNickId: 100,
            mask: '*!*@test.com'
        );

        $this->repository->save($akick);
        $this->flushAndClear();

        self::assertNull($this->repository->findByChannelAndMask(1, 'other!*@host.com'));
    }

    #[Test]
    public function findByChannelAndMaskReturnsNullWhenNone(): void
    {
        self::assertNull($this->repository->findByChannelAndMask(999, '*!*@none.com'));
    }

    #[Test]
    public function countByChannelReturnsCorrectCount(): void
    {
        $akick1 = ChannelAkick::create(channelId: 1, creatorNickId: 100, mask: '*!*@a.com');
        $akick2 = ChannelAkick::create(channelId: 1, creatorNickId: 101, mask: '*!*@b.com');
        $akick3 = ChannelAkick::create(channelId: 2, creatorNickId: 100, mask: '*!*@c.com');

        $this->repository->save($akick1);
        $this->repository->save($akick2);
        $this->repository->save($akick3);
        $this->flushAndClear();

        self::assertSame(2, $this->repository->countByChannel(1));
        self::assertSame(1, $this->repository->countByChannel(2));
        self::assertSame(0, $this->repository->countByChannel(999));
    }

    #[Test]
    public function findExpiredReturnsOnlyExpiredAkick(): void
    {
        $expiredPast = new DateTimeImmutable('-1 hour');
        $futureExpiry = new DateTimeImmutable('+1 hour');

        $expired = ChannelAkick::create(
            channelId: 1,
            creatorNickId: 100,
            mask: '*!*@expired.com',
            reason: 'Expired',
            expiresAt: $expiredPast
        );

        $stillValid = ChannelAkick::create(
            channelId: 1,
            creatorNickId: 101,
            mask: '*!*@valid.com',
            reason: 'Valid',
            expiresAt: $futureExpiry
        );

        $noExpiry = ChannelAkick::create(
            channelId: 1,
            creatorNickId: 102,
            mask: '*!*@permanent.com',
            reason: 'Permanent'
        );

        $this->repository->save($expired);
        $this->repository->save($stillValid);
        $this->repository->save($noExpiry);
        $this->flushAndClear();

        $expiredList = $this->repository->findExpired();

        self::assertCount(1, $expiredList);
        self::assertSame('*!*@expired.com', $expiredList[0]->getMask());
    }

    #[Test]
    public function findExpiredReturnsEmptyArrayWhenNone(): void
    {
        $akick = ChannelAkick::create(
            channelId: 1,
            creatorNickId: 100,
            mask: '*!*@permanent.com'
        );

        $this->repository->save($akick);
        $this->flushAndClear();

        self::assertSame([], $this->repository->findExpired());
    }

    #[Test]
    public function findExpiredReturnsMultipleExpired(): void
    {
        $past1 = new DateTimeImmutable('-1 hour');
        $past2 = new DateTimeImmutable('-2 hours');

        $expired1 = ChannelAkick::create(
            channelId: 1,
            creatorNickId: 100,
            mask: '*!*@exp1.com',
            expiresAt: $past1
        );

        $expired2 = ChannelAkick::create(
            channelId: 2,
            creatorNickId: 101,
            mask: '*!*@exp2.com',
            expiresAt: $past2
        );

        $this->repository->save($expired1);
        $this->repository->save($expired2);
        $this->flushAndClear();

        $expiredList = $this->repository->findExpired();

        self::assertCount(2, $expiredList);
    }

    #[Test]
    public function findByChannelIdsReturnsAkickForMultipleChannels(): void
    {
        $akick1 = ChannelAkick::create(channelId: 1, creatorNickId: 100, mask: '*!*@a1.com');
        $akick2 = ChannelAkick::create(channelId: 2, creatorNickId: 100, mask: '*!*@a2.com');
        $akick3 = ChannelAkick::create(channelId: 3, creatorNickId: 100, mask: '*!*@a3.com');

        $this->repository->save($akick1);
        $this->repository->save($akick2);
        $this->repository->save($akick3);
        $this->flushAndClear();

        $result = $this->repository->findByChannelIds([1, 2]);

        self::assertCount(2, $result);
    }

    #[Test]
    public function findByChannelIdsReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], $this->repository->findByChannelIds([]));
    }

    #[Test]
    public function findByChannelIdsReturnsEmptyArrayWhenNoneMatch(): void
    {
        $akick = ChannelAkick::create(channelId: 1, creatorNickId: 100, mask: '*!*@test.com');

        $this->repository->save($akick);
        $this->flushAndClear();

        self::assertSame([], $this->repository->findByChannelIds([999, 998]));
    }

    #[Test]
    public function findByChannelIdsReturnsAllMatchingChannels(): void
    {
        $akick1 = ChannelAkick::create(channelId: 1, creatorNickId: 100, mask: '*!*@1.com');
        $akick2 = ChannelAkick::create(channelId: 2, creatorNickId: 101, mask: '*!*@2.com');
        $akick3 = ChannelAkick::create(channelId: 1, creatorNickId: 102, mask: '*!*@1b.com');
        $akick4 = ChannelAkick::create(channelId: 3, creatorNickId: 103, mask: '*!*@3.com');

        $this->repository->save($akick1);
        $this->repository->save($akick2);
        $this->repository->save($akick3);
        $this->repository->save($akick4);
        $this->flushAndClear();

        $result = $this->repository->findByChannelIds([1, 3]);

        self::assertCount(3, $result);
    }
}
