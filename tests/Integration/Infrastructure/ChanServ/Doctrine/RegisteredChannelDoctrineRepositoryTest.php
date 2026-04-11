<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\ChanServ\Doctrine;

use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Infrastructure\ChanServ\Doctrine\RegisteredChannelDoctrineRepository;
use App\Tests\Integration\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RegisteredChannelDoctrineRepository::class)]
#[Group('integration')]
final class RegisteredChannelDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private RegisteredChannelRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new RegisteredChannelDoctrineRepository($this->entityManager);
    }

    #[Test]
    public function savePersistsChannel(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test channel');

        $this->repository->save($channel);
        $this->flushAndClear();

        $found = $this->repository->findByChannelName('#test');

        self::assertNotNull($found);
        self::assertSame('#test', $found->getName());
        self::assertSame('Test channel', $found->getDescription());
    }

    #[Test]
    public function findByChannelNameIsCaseInsensitive(): void
    {
        $channel = RegisteredChannel::register('#TestChannel', 1, 'Test');
        $this->repository->save($channel);
        $this->flushAndClear();

        self::assertNotNull($this->repository->findByChannelName('#TestChannel'));
        self::assertNotNull($this->repository->findByChannelName('#testchannel'));
        self::assertNotNull($this->repository->findByChannelName('#TESTCHANNEL'));
    }

    #[Test]
    public function findByChannelNameReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByChannelName('#nonexistent'));
    }

    #[Test]
    public function existsByChannelNameReturnsTrueWhenExists(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $this->repository->save($channel);
        $this->flushAndClear();

        self::assertTrue($this->repository->existsByChannelName('#test'));
        self::assertTrue($this->repository->existsByChannelName('#TEST'));
    }

    #[Test]
    public function existsByChannelNameReturnsFalseWhenNotExists(): void
    {
        self::assertFalse($this->repository->existsByChannelName('#nonexistent'));
    }

    #[Test]
    public function findByFounderNickIdReturnsChannels(): void
    {
        $channel1 = RegisteredChannel::register('#alpha', 1, 'Alpha');
        $channel2 = RegisteredChannel::register('#beta', 1, 'Beta');
        $channel3 = RegisteredChannel::register('#gamma', 2, 'Gamma');

        $this->repository->save($channel1);
        $this->repository->save($channel2);
        $this->repository->save($channel3);
        $this->flushAndClear();

        $founder1Channels = $this->repository->findByFounderNickId(1);

        self::assertCount(2, $founder1Channels);
        $names = array_map(static fn ($c) => $c->getName(), $founder1Channels);
        self::assertContains('#alpha', $names);
        self::assertContains('#beta', $names);
    }

    #[Test]
    public function findByFounderNickIdReturnsEmptyArrayWhenNone(): void
    {
        $founder = $this->repository->findByFounderNickId(999);

        self::assertSame([], $founder);
    }

    #[Test]
    public function findBySuccessorNickIdReturnsChannels(): void
    {
        $channel1 = RegisteredChannel::register('#alpha', 1, 'Alpha');
        $channel1->assignSuccessor(10);

        $channel2 = RegisteredChannel::register('#beta', 2, 'Beta');
        $channel2->assignSuccessor(10);

        $channel3 = RegisteredChannel::register('#gamma', 3, 'Gamma');
        $channel3->assignSuccessor(20);

        $this->repository->save($channel1);
        $this->repository->save($channel2);
        $this->repository->save($channel3);
        $this->flushAndClear();

        $successor10Channels = $this->repository->findBySuccessorNickId(10);

        self::assertCount(2, $successor10Channels);
        $names = array_map(static fn ($c) => $c->getName(), $successor10Channels);
        self::assertContains('#alpha', $names);
        self::assertContains('#beta', $names);
    }

    #[Test]
    public function deleteRemovesChannel(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $this->repository->save($channel);
        $this->entityManager->flush();

        $this->repository->delete($channel);
        $this->flushAndClear();

        self::assertNull($this->repository->findByChannelName('#test'));
    }

    #[Test]
    public function listAllReturnsAllChannelsOrderedByName(): void
    {
        $channel1 = RegisteredChannel::register('#zeta', 1, 'Z');
        $channel2 = RegisteredChannel::register('#alpha', 2, 'A');
        $channel3 = RegisteredChannel::register('#beta', 3, 'B');

        $this->repository->save($channel1);
        $this->repository->save($channel2);
        $this->repository->save($channel3);
        $this->flushAndClear();

        $all = $this->repository->listAll();

        self::assertCount(3, $all);
        $names = array_map(static fn ($c) => $c->getName(), $all);
        self::assertSame(['#alpha', '#beta', '#zeta'], $names);
    }

    #[Test]
    public function listAllReturnsEmptyArrayWhenNone(): void
    {
        self::assertSame([], $this->repository->listAll());
    }

    #[Test]
    public function findByIdsReturnsMatchingChannels(): void
    {
        $channel1 = RegisteredChannel::register('#alpha', 1, 'A');
        $channel2 = RegisteredChannel::register('#beta', 2, 'B');
        $channel3 = RegisteredChannel::register('#gamma', 3, 'G');

        $this->repository->save($channel1);
        $this->repository->save($channel2);
        $this->repository->save($channel3);
        $this->flushAndClear();

        $ids = [$channel1->getId(), $channel3->getId()];
        $found = $this->repository->findByIds($ids);

        self::assertCount(2, $found);
        $names = array_map(static fn ($c) => $c->getName(), $found);
        self::assertContains('#alpha', $names);
        self::assertContains('#gamma', $names);
        self::assertNotContains('#beta', $names);
    }

    #[Test]
    public function findByIdsReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], $this->repository->findByIds([]));
    }

    #[Test]
    public function findRegisteredInactiveSinceReturnsOldChannels(): void
    {
        $oldChannel = RegisteredChannel::register('#old', 1, 'Old');
        $this->repository->save($oldChannel);
        $this->entityManager->flush();
        $this->entityManager->createQueryBuilder()
            ->update(RegisteredChannel::class, 'c')
            ->set('c.lastUsedAt', ':date')
            ->where('c.nameLower = :name')
            ->setParameter('date', new DateTimeImmutable('-60 days'))
            ->setParameter('name', '#old')
            ->getQuery()
            ->execute();
        $this->entityManager->clear();

        $newChannel = RegisteredChannel::register('#new', 2, 'New');
        $newChannel->touchLastUsed();
        $this->repository->save($newChannel);
        $this->flushAndClear();

        $inactive = $this->repository->findRegisteredInactiveSince(new DateTimeImmutable('-30 days'));

        self::assertCount(1, $inactive);
        self::assertSame('#old', $inactive[0]->getName());
    }

    #[Test]
    public function findRegisteredInactiveSinceReturnsEmptyArrayWhenNoneInactive(): void
    {
        $channel = RegisteredChannel::register('#active', 1, 'Active');
        $channel->touchLastUsed();

        $this->repository->save($channel);
        $this->flushAndClear();

        $inactive = $this->repository->findRegisteredInactiveSince(new DateTimeImmutable('-30 days'));

        self::assertSame([], $inactive);
    }

    #[Test]
    public function clearSuccessorNickIdSetsSuccessorToNull(): void
    {
        $channel1 = RegisteredChannel::register('#alpha', 1, 'Alpha');
        $channel1->assignSuccessor(100);

        $channel2 = RegisteredChannel::register('#beta', 2, 'Beta');
        $channel2->assignSuccessor(100);

        $channel3 = RegisteredChannel::register('#gamma', 3, 'Gamma');
        $channel3->assignSuccessor(200);

        $this->repository->save($channel1);
        $this->repository->save($channel2);
        $this->repository->save($channel3);
        $this->flushAndClear();

        $this->repository->clearSuccessorNickId(100);
        $this->flushAndClear();

        $found1 = $this->repository->findByChannelName('#alpha');
        $found2 = $this->repository->findByChannelName('#beta');
        $found3 = $this->repository->findByChannelName('#gamma');

        self::assertNotNull($found1);
        self::assertNotNull($found2);
        self::assertNotNull($found3);

        self::assertNull($found1->getSuccessorNickId());
        self::assertNull($found2->getSuccessorNickId());
        self::assertSame(200, $found3->getSuccessorNickId());
    }

    #[Test]
    public function clearSuccessorNickIdDoesNothingWhenNoMatch(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $channel->assignSuccessor(100);

        $this->repository->save($channel);
        $this->flushAndClear();

        $this->repository->clearSuccessorNickId(999);
        $this->flushAndClear();

        $found = $this->repository->findByChannelName('#test');
        self::assertNotNull($found);
        self::assertSame(100, $found->getSuccessorNickId());
    }

    #[Test]
    public function findExpiredSuspensionsReturnsOnlyExpiredSuspendedChannels(): void
    {
        $expired = RegisteredChannel::register('#expired', 1, 'Expired');
        $expired->suspend('Abuse', new DateTimeImmutable('-1 day'));

        $active = RegisteredChannel::register('#active', 2, 'Active suspension');
        $active->suspend('Abuse', new DateTimeImmutable('+7 days'));

        $permanent = RegisteredChannel::register('#permanent', 3, 'Permanent suspension');
        $permanent->suspend('Abuse', null);

        $normal = RegisteredChannel::register('#normal', 4, 'Normal');

        $this->repository->save($expired);
        $this->repository->save($active);
        $this->repository->save($permanent);
        $this->repository->save($normal);
        $this->flushAndClear();

        $result = $this->repository->findExpiredSuspensions();

        self::assertCount(1, $result);
        self::assertSame('#expired', $result[0]->getName());
    }
}
