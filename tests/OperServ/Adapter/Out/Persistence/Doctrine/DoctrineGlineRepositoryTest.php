<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Adapter\Out\Persistence\Doctrine\DoctrineGlineRepository;
use App\OperServ\Application\Port\Out\GlineEntry;
use App\OperServ\Domain\Entity\Gline;
use DateTimeImmutable;
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

use function sprintf;

#[CoversClass(DoctrineGlineRepository::class)]
#[CoversClass(GlineEntry::class)]
final class DoctrineGlineRepositoryTest extends TestCase
{
    #[Test]
    public function findsByMaskAndReturnsNullForUnexpectedValues(): void
    {
        $gline = $this->gline(7);
        $objectRepository = $this->createMock(EntityRepository::class);
        $objectRepository->expects(self::exactly(2))->method('findOneBy')
            ->with(self::logicalOr(['mask' => '*@host.test'], ['mask' => '*@missing.test']))
            ->willReturnOnConsecutiveCalls($gline, new stdClass());
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($objectRepository);
        $adapter = new DoctrineGlineRepository($entityManager);

        $entry = $adapter->findByMask('*@host.test');

        self::assertNotNull($entry);
        self::assertSame(7, $entry->id);
        self::assertSame('*@host.test', $entry->mask);
        self::assertSame(42, $entry->creatorAccountId);
        self::assertSame('abuse', $entry->reason);
        self::assertNull($adapter->findByMask('*@missing.test'));
    }

    #[Test]
    public function findsAllInExpectedOrderAndFiltersUnexpectedValues(): void
    {
        $gline = $this->gline(7);
        $objectRepository = $this->createMock(EntityRepository::class);
        $objectRepository->expects(self::once())->method('findBy')
            ->with([], ['createdAt' => 'DESC'])
            ->willReturn([$gline, new stdClass()]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($objectRepository);

        $entries = new DoctrineGlineRepository($entityManager)->findAll();

        self::assertCount(1, $entries);
        self::assertSame('*@host.test', $entries[0]->mask);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function boundedQueryProvider(): iterable
    {
        yield 'pattern' => [
            'findByMaskPattern',
            'SELECT g FROM %s g WHERE LOWER(g.mask) LIKE :pattern ORDER BY g.createdAt DESC',
            '%host%',
        ];
        yield 'active' => [
            'findActiveAt',
            'SELECT g FROM %s g WHERE g.expiresAt IS NULL OR g.expiresAt > :at ORDER BY g.createdAt DESC',
            'at',
        ];
        yield 'expired' => [
            'findExpiredAt',
            'SELECT g FROM %s g WHERE g.expiresAt IS NOT NULL AND g.expiresAt <= :at ORDER BY g.createdAt DESC',
            'at',
        ];
    }

    #[DataProvider('boundedQueryProvider')]
    #[Test]
    public function executesBoundedQueries(string $method, string $dql, string $parameterValue): void
    {
        $at = new DateTimeImmutable('2026-09-08T10:00:00+00:00');
        $gline = $this->gline(7);
        $query = $this->createMock(Query::class);
        $expectedValue = 'at' === $parameterValue ? $at : $parameterValue;
        $query->expects(self::once())->method('setParameter')
            ->with('findByMaskPattern' === $method ? 'pattern' : 'at', $expectedValue)
            ->willReturnSelf();
        $query->expects(self::once())->method('getResult')->willReturn([$gline, 'invalid']);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('createQuery')
            ->with(sprintf($dql, Gline::class))
            ->willReturn($query);
        $adapter = new DoctrineGlineRepository($entityManager);

        $entries = match ($method) {
            'findByMaskPattern' => $adapter->findByMaskPattern('HOST'),
            'findActiveAt' => $adapter->findActiveAt($at),
            default => $adapter->findExpiredAt($at),
        };

        self::assertCount(1, $entries);
        self::assertSame('*@host.test', $entries[0]->mask);
    }

    #[Test]
    public function countsAllEntries(): void
    {
        $query = $this->createMock(Query::class);
        $query->expects(self::once())->method('getSingleScalarResult')->willReturn('3');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('createQuery')
            ->with(sprintf('SELECT COUNT(g.id) FROM %s g', Gline::class))
            ->willReturn($query);

        self::assertSame(3, new DoctrineGlineRepository($entityManager)->countAll());
    }

    #[Test]
    public function persistsNewGline(): void
    {
        $createdAt = new DateTimeImmutable('2026-09-08T10:00:00+00:00');
        $expiresAt = new DateTimeImmutable('2026-09-09T10:00:00+00:00');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(
            static fn (Gline $gline): bool => '*@host.test' === $gline->getMask()
                && 42 === $gline->getCreatorNickId()
                && 'abuse' === $gline->getReason()
                && $createdAt === $gline->getCreatedAt()
                && $expiresAt === $gline->getExpiresAt(),
        ));
        $entityManager->expects(self::once())->method('flush');

        new DoctrineGlineRepository($entityManager)->save('*@host.test', 42, 'abuse', $createdAt, $expiresAt);
    }

    #[Test]
    public function removesByIdentifierAndIgnoresMissingEntry(): void
    {
        $gline = $this->gline(7);
        $entry = $this->entry(7);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('find')
            ->with(Gline::class, 7)
            ->willReturnOnConsecutiveCalls(null, $gline);
        $entityManager->expects(self::once())->method('remove')->with($gline);
        $entityManager->expects(self::once())->method('flush');
        $adapter = new DoctrineGlineRepository($entityManager);

        $adapter->remove($entry);
        $adapter->remove($entry);
    }

    #[Test]
    public function removesLegacyEntryWithoutIdentifierByMask(): void
    {
        $gline = $this->gline(7);
        $objectRepository = $this->createMock(EntityRepository::class);
        $objectRepository->expects(self::once())->method('findOneBy')
            ->with(['mask' => '*@host.test'])
            ->willReturn($gline);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($objectRepository);
        $entityManager->expects(self::once())->method('remove')->with($gline);
        $entityManager->expects(self::once())->method('flush');

        new DoctrineGlineRepository($entityManager)->remove($this->entry(null));
    }

    #[Test]
    public function clearsCreatorAccountId(): void
    {
        $query = $this->createMock(Query::class);
        $query->expects(self::once())->method('setParameter')->with('accountId', 42)->willReturnSelf();
        $query->expects(self::once())->method('execute')->willReturn(2);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('createQuery')
            ->with(sprintf('UPDATE %s g SET g.creatorNickId = NULL WHERE g.creatorNickId = :accountId', Gline::class))
            ->willReturn($query);

        new DoctrineGlineRepository($entityManager)->clearCreatorAccountId(42);
    }

    #[Test]
    public function rejectsMappedGlineWithoutPositiveIdentifier(): void
    {
        $gline = $this->gline(0);
        $objectRepository = $this->createStub(EntityRepository::class);
        $objectRepository->method('findOneBy')->willReturn($gline);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($objectRepository);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Persisted GLINE entry must have an identifier.');

        new DoctrineGlineRepository($entityManager)->findByMask('*@host.test');
    }

    private function gline(int $id): Gline
    {
        $gline = Gline::create(
            new DateTimeImmutable('2026-09-08T10:00:00+00:00'),
            '*@host.test',
            42,
            'abuse',
            new DateTimeImmutable('2026-09-09T10:00:00+00:00'),
        );
        new ReflectionProperty(Gline::class, 'id')->setValue($gline, $id);

        return $gline;
    }

    private function entry(?int $id): GlineEntry
    {
        return new GlineEntry(
            '*@host.test',
            42,
            'abuse',
            new DateTimeImmutable('2026-09-08T10:00:00+00:00'),
            new DateTimeImmutable('2026-09-09T10:00:00+00:00'),
            $id,
        );
    }
}
