<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Adapter\Out\Persistence\Doctrine\DoctrineMotdRepository;
use App\OperServ\Adapter\Out\Persistence\Doctrine\Entity\MotdRecord;
use App\OperServ\Application\Model\MessageDelivery;
use App\OperServ\Application\Port\Out\MotdEntry;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

use function count;
use function sprintf;

#[CoversClass(DoctrineMotdRepository::class)]
#[CoversClass(MotdRecord::class)]
#[CoversClass(MotdEntry::class)]
final class DoctrineMotdRepositoryTest extends TestCase
{
    #[Test]
    public function deliveriesRecordedDuringAFlushRemainPending(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $connection = $this->createMock(Connection::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $repository = new DoctrineMotdRepository($entityManager);
        $updates = [];
        $connection->expects(self::exactly(2))->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params) use ($repository, &$updates): int {
                $updates[] = $params;
                if (1 === count($updates)) {
                    $repository->recordShown(7);
                }

                return 0;
            });
        $repository->recordShown(7);
        $repository->flushShownCounts();
        $repository->flushShownCounts();

        self::assertSame([[1, 7], [1, 7]], $updates);
    }

    #[Test]
    public function reentrantFlushDoesNotRepeatAnInFlightUpdate(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $connection = $this->createMock(Connection::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $repository = new DoctrineMotdRepository($entityManager);
        $connection->expects(self::once())->method('executeStatement')
            ->willReturnCallback(static function () use ($repository): int {
                $repository->flushShownCounts();

                return 0;
            });
        $repository->recordShown(7);
        $repository->flushShownCounts();
    }

    #[Test]
    public function pendingDeliveriesAreVisibleWhenReadingAnEntry(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($this->record(7));
        $repository = new DoctrineMotdRepository($entityManager);
        $repository->recordShown(7);
        $repository->recordShown(7);

        self::assertSame(2, $repository->findById(7)?->shownCount);
    }

    #[Test]
    public function recordingDeliveriesDoesNotQueryOrFlush(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('find');
        $entityManager->expects(self::never())->method('flush');
        $repository = new DoctrineMotdRepository($entityManager);

        $repository->recordShown(7);
        $repository->recordShown(7);
    }

    #[Test]
    public function activeCacheRefreshesWhenTheEarliestMotdExpires(): void
    {
        $expiresAt = new DateTimeImmutable('2026-09-08T10:00:01+00:00');
        $query = $this->createMock(Query::class);
        $query->expects(self::exactly(2))->method('setParameter')->willReturnSelf();
        $query->expects(self::exactly(2))->method('getResult')
            ->willReturnOnConsecutiveCalls([$this->record(7, expiresAt: $expiresAt)], []);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('createQuery')->willReturn($query);
        $repository = new DoctrineMotdRepository($entityManager);

        self::assertCount(1, $repository->findActiveAt($expiresAt->modify('-1 second')));
        self::assertSame([], $repository->findActiveAt($expiresAt));
    }

    #[Test]
    public function activeMotdsAreLoadedOnlyOnceWhileCacheIsWarm(): void
    {
        $query = $this->createMock(Query::class);
        $query->expects(self::once())->method('setParameter')->willReturnSelf();
        $query->expects(self::once())->method('getResult')->willReturn([$this->record(7)]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('createQuery')->willReturn($query);
        $repository = new DoctrineMotdRepository($entityManager);
        $now = new DateTimeImmutable('2026-09-08T10:00:00+00:00');

        self::assertCount(1, $repository->findActiveAt($now));
        self::assertCount(1, $repository->findActiveAt($now->modify('+1 second')));
    }

    /** @return iterable<string, array{MessageDelivery, string}> */
    public static function deliveryProvider(): iterable
    {
        yield 'interactive' => [MessageDelivery::Interactive, 'PRIVMSG'];
        yield 'non-interactive' => [MessageDelivery::NonInteractive, 'NOTICE'];
    }

    #[DataProvider('deliveryProvider')]
    #[Test]
    public function persistsAndTranslatesNewMotd(MessageDelivery $delivery, string $storedType): void
    {
        $createdAt = new DateTimeImmutable('2026-09-08T10:00:00+00:00');
        $expiresAt = new DateTimeImmutable('2026-09-09T10:00:00+00:00');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(
            function (MotdRecord $motd) use ($storedType): bool {
                self::assertSame($storedType, $motd->getMessageType());
                $this->assignId($motd, 7);

                return true;
            },
        ));
        $entityManager->expects(self::once())->method('flush');

        $entry = new DoctrineMotdRepository($entityManager)->add('Welcome', 'NickServ', $delivery, 42, $createdAt, $expiresAt);

        self::assertSame(7, $entry->id);
        self::assertSame('Welcome', $entry->text);
        self::assertSame('NickServ', $entry->botNickname);
        self::assertSame($delivery, $entry->delivery);
        self::assertTrue($entry->enabled);
        self::assertSame($createdAt, $entry->createdAt);
        self::assertSame($expiresAt, $entry->expiresAt);
        self::assertSame(0, $entry->shownCount);
        self::assertSame(42, $entry->creatorAccountId);
        self::assertTrue($entry->isExpiredAt($expiresAt));
        self::assertFalse($entry->isExpiredAt($createdAt));
    }

    #[Test]
    public function findsAndTranslatesExistingMotdOrReturnsNull(): void
    {
        $motd = $this->record(7, messageType: 'NOTICE');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('find')
            ->with(MotdRecord::class, self::logicalOr(7, 8))
            ->willReturnOnConsecutiveCalls($motd, new stdClass());
        $adapter = new DoctrineMotdRepository($entityManager);

        $entry = $adapter->findById(7);
        self::assertNotNull($entry);
        self::assertSame(MessageDelivery::NonInteractive, $entry->delivery);
        self::assertNull($adapter->findById(8));
    }

    #[Test]
    public function findsAllInExpectedOrderAndFiltersUnexpectedValues(): void
    {
        $motd = $this->record(7);
        $objectRepository = $this->createMock(EntityRepository::class);
        $objectRepository->expects(self::once())->method('findBy')
            ->with([], ['createdAt' => 'DESC'])
            ->willReturn([$motd, new stdClass()]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($objectRepository);

        $entries = new DoctrineMotdRepository($entityManager)->findAll();

        self::assertCount(1, $entries);
        self::assertSame('Welcome', $entries[0]->text);
    }

    /** @return iterable<string, array{string, string}> */
    public static function boundedQueryProvider(): iterable
    {
        yield 'active' => [
            'findActiveAt',
            'SELECT m FROM %s m
                 WHERE m.enabled = true AND (m.expiresAt IS NULL OR m.expiresAt > :at)
                 ORDER BY m.createdAt ASC',
        ];
        yield 'expired' => [
            'findExpiredAt',
            'SELECT m FROM %s m
                 WHERE m.expiresAt IS NOT NULL AND m.expiresAt <= :at
                 ORDER BY m.createdAt ASC',
        ];
    }

    #[DataProvider('boundedQueryProvider')]
    #[Test]
    public function findsMotdsUsingBoundedQuery(string $method, string $dql): void
    {
        $at = new DateTimeImmutable('2026-09-08T10:00:00+00:00');
        $motd = $this->record(7, expiresAt: $at);
        $query = $this->createMock(Query::class);
        $query->expects(self::once())->method('setParameter')->with('at', $at)->willReturnSelf();
        $query->expects(self::once())->method('getResult')->willReturn([$motd, 'invalid']);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('createQuery')
            ->with(sprintf($dql, MotdRecord::class))
            ->willReturn($query);
        $adapter = new DoctrineMotdRepository($entityManager);

        $entries = 'findActiveAt' === $method ? $adapter->findActiveAt($at) : $adapter->findExpiredAt($at);

        self::assertCount(1, $entries);
        self::assertSame('Welcome', $entries[0]->text);
    }

    #[Test]
    public function removesExistingMotdAndIgnoresMissingOne(): void
    {
        $motd = $this->record(7);
        $entry = $this->entry(7);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('find')
            ->with(MotdRecord::class, 7)
            ->willReturnOnConsecutiveCalls(null, $motd);
        $entityManager->expects(self::once())->method('remove')->with($motd);
        $entityManager->expects(self::once())->method('flush');
        $adapter = new DoctrineMotdRepository($entityManager);

        $adapter->remove($entry);
        $adapter->remove($entry);
    }

    #[Test]
    public function flushesAccumulatedDeliveriesUsingAtomicUpdates(): void
    {
        $motd = $this->record(7);
        $updates = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params) use (&$updates): int {
                $updates[] = [$sql, $params];

                return 1;
            });
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->expects(self::exactly(2))->method('find')
            ->with(MotdRecord::class, self::logicalOr(7, 8))
            ->willReturnOnConsecutiveCalls($motd, null);
        $entityManager->expects(self::once())->method('refresh')->with($motd);
        $entityManager->expects(self::never())->method('flush');
        $adapter = new DoctrineMotdRepository($entityManager);

        $adapter->recordShown(7);
        $adapter->recordShown(7);
        $adapter->recordShown(8);
        $adapter->flushShownCounts();
        $adapter->flushShownCounts();

        self::assertSame([
            ['UPDATE motd SET shown_count = shown_count + ? WHERE id = ?', [2, 7]],
            ['UPDATE motd SET shown_count = shown_count + ? WHERE id = ?', [1, 8]],
        ], $updates);
    }

    #[Test]
    public function deletesMotdsByCreatorAccountId(): void
    {
        $query = $this->createMock(Query::class);
        $query->expects(self::once())->method('setParameter')->with('accountId', 42)->willReturnSelf();
        $query->expects(self::once())->method('execute')->willReturn(2);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('createQuery')
            ->with(sprintf('DELETE FROM %s m WHERE m.creatorNickId = :accountId', MotdRecord::class))
            ->willReturn($query);

        new DoctrineMotdRepository($entityManager)->deleteByCreatorAccountId(42);
    }

    #[Test]
    public function rejectsReadingIdentifierBeforePersistence(): void
    {
        $motd = MotdRecord::create(
            'Welcome',
            'NickServ',
            'NOTICE',
            null,
            new DateTimeImmutable('2026-09-08T10:00:00+00:00'),
            null,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Persisted MOTD entry must have an identifier.');

        $motd->getId();
    }

    private function record(
        int $id,
        string $messageType = 'PRIVMSG',
        ?DateTimeImmutable $expiresAt = null,
    ): MotdRecord {
        $motd = MotdRecord::create(
            'Welcome',
            'NickServ',
            $messageType,
            42,
            new DateTimeImmutable('2026-09-08T10:00:00+00:00'),
            $expiresAt,
        );
        $this->assignId($motd, $id);

        return $motd;
    }

    private function assignId(MotdRecord $motd, int $id): void
    {
        new ReflectionProperty(MotdRecord::class, 'id')->setValue($motd, $id);
    }

    private function entry(int $id): MotdEntry
    {
        return new MotdEntry(
            $id,
            'Welcome',
            'NickServ',
            MessageDelivery::Interactive,
            true,
            new DateTimeImmutable('2026-09-08T10:00:00+00:00'),
            null,
            0,
            42,
        );
    }
}
