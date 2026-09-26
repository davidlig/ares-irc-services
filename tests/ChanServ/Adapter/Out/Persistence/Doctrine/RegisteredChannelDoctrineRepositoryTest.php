<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Adapter\Out\Persistence\Doctrine\RegisteredChannelDoctrineRepository;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelStatus;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NativeQuery;
use Doctrine\ORM\Query\ResultSetMapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

use function array_slice;
use function count;
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
    #[DataProvider('listPlatforms')]
    public function countUsesThePlatformSearchKeyAndRetainsTheLiteralLikeCheck(string $engine): void
    {
        $platform = match ($engine) {
            'mysql' => new MySQLPlatform(),
            'mariadb' => new MariaDBPlatform(),
            'postgres' => new PostgreSQLPlatform(),
            default => new SQLitePlatform(),
        };
        $expression = match ($engine) {
            'mysql', 'mariadb' => 'c.list_search_key',
            'sqlite' => 'c.name_lower',
            default => 'LOWER(c.name_lower)',
        };
        $equality = 'sqlite' === $engine ? $expression . ' COLLATE NOCASE' : $expression;
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::once())->method('fetchOne')
            ->with(
                'SELECT COUNT(c.id) FROM registered_channels c WHERE ' . $equality . ' = :exact AND ' . $expression . " LIKE :pattern ESCAPE '!'",
                ['pattern' => '#name!!!%!_', 'exact' => '#name!%_'],
            )
            ->willReturn('2');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        self::assertSame(2, new RegisteredChannelDoctrineRepository($em)->countByPattern('#NAME!%_'));
    }

    #[Test]
    public function countReturnsZeroWhenTheDriverHasNoScalarResult(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('fetchOne')->willReturn(false);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        self::assertSame(0, new RegisteredChannelDoctrineRepository($em)->countByPattern('*'));
    }

    /** @return iterable<string, array{string}> */
    public static function listPlatforms(): iterable
    {
        foreach (['sqlite', 'postgres', 'mysql', 'mariadb'] as $engine) {
            yield $engine => [$engine];
        }
    }

    #[Test]
    public function nativePageBindsItsPatternAndFiltersUnexpectedHydrationRows(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $entity = RegisteredChannel::register(new DateTimeImmutable('2026-09-27'), '#native', 1, 'Test');
        $query = $this->createMock(NativeQuery::class);
        $query->expects(self::once())->method('setParameters')
            ->with(['pattern' => '#na%'])->willReturnSelf();
        $query->expects(self::once())->method('getResult')->willReturn(['unexpected', $entity]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->willReturn($this->entityManager->getClassMetadata(RegisteredChannel::class));
        $em->expects(self::once())->method('createNativeQuery')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, "c.list_search_key LIKE :pattern ESCAPE '!'")
                    && str_ends_with($sql, 'ORDER BY c.name_lower ASC, c.id ASC LIMIT 2 OFFSET 3')),
                self::callback(static fn (ResultSetMapping $mapping): bool => ChannelStatus::class === ($mapping->enumMappings['status'] ?? null)),
            )->willReturn($query);

        self::assertSame([$entity], new RegisteredChannelDoctrineRepository($em)->searchByPattern('#Na*', 3, 2));
    }

    #[Test]
    public function listPreservesLegacyMatchesForUnicodeLiteralsAndPageBoundaries(): void
    {
        foreach (['#Alpha', '#Alpine', '#Literal!%_?[]\\\\Name', '#École', '#éclair', '#İstanbul', '#space '] as $index => $name) {
            $this->entityManager->persist(RegisteredChannel::register(new DateTimeImmutable('2026-09-27'), $name, 1, 'Test'));
        }
        $this->flushAndClear();
        $connection = $this->entityManager->getConnection();

        $connection->update('registered_channels', ['name_lower' => '#AlPhA'], ['name' => '#Alpha']);
        $connection->update('registered_channels', ['name_lower' => '#ÉcOlE'], ['name' => '#École']);

        foreach (['*', '#ALPHA', '#Al*', '*a*', '**a**', '#Literal!%_?[]\\\\Name', '#É*', '#é*', '#İ*', '#space ', '#space'] as $pattern) {
            $like = strtr(strtolower($pattern), ['!' => '!!', '%' => '!%', '_' => '!_', '*' => '%']);
            $legacy = $connection->fetchFirstColumn(
                "SELECT name FROM registered_channels WHERE LOWER(name_lower) LIKE ? ESCAPE '!' ORDER BY name_lower ASC, id ASC",
                [$like],
            );
            self::assertSame(count($legacy), $this->repository->countByPattern($pattern), $pattern);
            foreach ([[0, 10], [1, 2], [-5, 1], [0, 0], [0, -2], [999, 5]] as [$offset, $limit]) {
                $actual = array_map(static fn (RegisteredChannel $entity): string => $entity->getName(), $this->repository->searchByPattern($pattern, $offset, $limit));
                self::assertSame(array_slice($legacy, max(0, $offset), max(0, $limit)), $actual, $pattern);
            }
        }
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
    public function countsAndSearchesByStrictCaseInsensitiveGlobWithBoundedStablePages(): void
    {
        $channels = [
            RegisteredChannel::register(new DateTimeImmutable(), '#davidlig', 1, 'Davidlig'),
            RegisteredChannel::register(new DateTimeImmutable(), '#davinia', 2, 'Davinia'),
            RegisteredChannel::register(new DateTimeImmutable(), '#david', 3, 'David'),
            RegisteredChannel::register(new DateTimeImmutable(), '#davi', 4, 'Davi'),
        ];
        $channels[1]->suspend('Test suspension');
        $channels[2]->markPendingDeletion(new DateTimeImmutable());
        $channels[3] = RegisteredChannel::createForbidden(new DateTimeImmutable(), '#davi', 'Test forbidden');
        foreach ($channels as $channel) {
            $this->entityManager->persist($channel);
        }
        $this->flushAndClear();

        self::assertSame(4, $this->repository->countByPattern('*AVI*'));
        self::assertSame(1, $this->repository->countByPattern('*avid'));
        self::assertSame(0, $this->repository->countByPattern('*?vid'));

        $secondPage = $this->repository->searchByPattern('*avi*', 1, 2);

        self::assertSame(['#david', '#davidlig'], array_map(static fn (RegisteredChannel $channel): string => $channel->getName(), $secondPage));
        self::assertSame([
            ChannelStatus::PendingDeletion,
            ChannelStatus::Active,
        ], array_map(static fn (RegisteredChannel $channel): ChannelStatus => $channel->getStatus(), $secondPage));
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
