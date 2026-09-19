<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Adapter\Out\Persistence\Doctrine\RegisteredChannelDoctrineRepository;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

use function sprintf;

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
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Test channel');

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
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#TestChannel', 1, 'Test');
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
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Test');
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
        $channel1 = RegisteredChannel::register(new DateTimeImmutable(), '#alpha', 1, 'Alpha');
        $channel2 = RegisteredChannel::register(new DateTimeImmutable(), '#beta', 1, 'Beta');
        $channel3 = RegisteredChannel::register(new DateTimeImmutable(), '#gamma', 2, 'Gamma');

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
        $channel1 = RegisteredChannel::register(new DateTimeImmutable(), '#alpha', 1, 'Alpha');
        $channel1->assignSuccessor(10);

        $channel2 = RegisteredChannel::register(new DateTimeImmutable(), '#beta', 2, 'Beta');
        $channel2->assignSuccessor(10);

        $channel3 = RegisteredChannel::register(new DateTimeImmutable(), '#gamma', 3, 'Gamma');
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
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Test');
        $this->repository->save($channel);
        $this->entityManager->flush();

        $this->repository->delete($channel);
        $this->flushAndClear();

        self::assertNull($this->repository->findByChannelName('#test'));
    }

    #[Test]
    public function iterateAllCrossesPageBoundaryInNameOrderAndDetachesProcessedChannels(): void
    {
        for ($number = 500; $number >= 0; --$number) {
            $this->entityManager->persist(RegisteredChannel::register(
                new DateTimeImmutable(),
                sprintf('#channel-%03d', $number),
                1,
                'Batch',
            ));
        }
        $this->flushAndClear();

        $names = [];
        foreach ($this->repository->iterateAll() as $channel) {
            $names[] = $channel->getName();
            self::assertTrue($this->entityManager->contains($channel));
        }

        self::assertCount(501, $names);
        self::assertSame('#channel-000', $names[0]);
        self::assertSame('#channel-500', $names[500]);
        self::assertFalse($this->entityManager->contains($channel));
    }

    #[Test]
    public function iterateFilteredMaintenanceScansKeepTheirPredicates(): void
    {
        $old = RegisteredChannel::register(new DateTimeImmutable('-60 days'), '#old', 1, 'Old');
        $fresh = RegisteredChannel::register(new DateTimeImmutable(), '#fresh', 1, 'Fresh');
        $suspended = RegisteredChannel::register(new DateTimeImmutable(), '#suspended', 1, 'Suspended');
        $suspended->suspend('Expired', new DateTimeImmutable('-1 day'));
        $pending = RegisteredChannel::register(new DateTimeImmutable(), '#pending', 1, 'Pending');
        $pending->markPendingDeletion(new DateTimeImmutable('-8 days'));
        $protected = RegisteredChannel::register(new DateTimeImmutable('-60 days'), '#protected', 2, 'Protected');
        $protected->changeNoExpire(true);
        foreach ([$old, $fresh, $suspended, $pending, $protected] as $channel) {
            $this->entityManager->persist($channel);
        }
        $this->flushAndClear();

        $inactive = iterator_to_array($this->repository->iterateRegisteredInactiveSince(new DateTimeImmutable('-30 days')));
        $expired = iterator_to_array($this->repository->iterateExpiredSuspensions(new DateTimeImmutable()));
        $deleted = iterator_to_array($this->repository->iteratePendingDeletionBefore(new DateTimeImmutable('-7 days')));

        self::assertSame(['#old'], array_map(static fn (RegisteredChannel $c): string => $c->getName(), $inactive));
        self::assertSame(['#suspended'], array_map(static fn (RegisteredChannel $c): string => $c->getName(), $expired));
        self::assertSame(['#pending'], array_map(static fn (RegisteredChannel $c): string => $c->getName(), $deleted));
    }

    #[Test]
    public function findByIdsReturnsMatchingChannels(): void
    {
        $channel1 = RegisteredChannel::register(new DateTimeImmutable(), '#alpha', 1, 'A');
        $channel2 = RegisteredChannel::register(new DateTimeImmutable(), '#beta', 2, 'B');
        $channel3 = RegisteredChannel::register(new DateTimeImmutable(), '#gamma', 3, 'G');

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
    public function clearSuccessorNickIdSetsSuccessorToNull(): void
    {
        $channel1 = RegisteredChannel::register(new DateTimeImmutable(), '#alpha', 1, 'Alpha');
        $channel1->assignSuccessor(100);

        $channel2 = RegisteredChannel::register(new DateTimeImmutable(), '#beta', 2, 'Beta');
        $channel2->assignSuccessor(100);

        $channel3 = RegisteredChannel::register(new DateTimeImmutable(), '#gamma', 3, 'Gamma');
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
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Test');
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
    public function findForbiddenChannelsReturnsOnlyForbiddenChannels(): void
    {
        $forbidden = RegisteredChannel::createForbidden(new DateTimeImmutable(), '#forbidden1', 'Spam channel');
        $forbidden2 = RegisteredChannel::createForbidden(new DateTimeImmutable(), '#forbidden2', 'Illegal content');
        $active = RegisteredChannel::register(new DateTimeImmutable(), '#active', 1, 'Active channel');
        $suspended = RegisteredChannel::register(new DateTimeImmutable(), '#suspended', 2, 'Suspended channel');
        $suspended->suspend('Abuse');

        $this->repository->save($forbidden);
        $this->repository->save($forbidden2);
        $this->repository->save($active);
        $this->repository->save($suspended);
        $this->flushAndClear();

        $result = $this->repository->findForbiddenChannels();

        self::assertCount(2, $result);
        $names = array_map(static fn (RegisteredChannel $c): string => $c->getName(), $result);
        self::assertContains('#forbidden1', $names);
        self::assertContains('#forbidden2', $names);
    }

    #[Test]
    public function findForbiddenChannelsReturnsEmptyArrayWhenNoneForbidden(): void
    {
        $active = RegisteredChannel::register(new DateTimeImmutable(), '#active', 1, 'Active channel');
        $this->repository->save($active);
        $this->flushAndClear();

        $result = $this->repository->findForbiddenChannels();

        self::assertSame([], $result);
    }
}
