<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Adapter\Out\Persistence\Doctrine\DoctrineMotdRepository;
use App\OperServ\Application\Model\MessageDelivery;
use App\OperServ\Application\Port\Out\MotdEntry;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

use function array_map;

#[CoversClass(DoctrineMotdRepository::class)]
#[Group('integration')]
final class DoctrineMotdRepositoryPagingTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function expiredEntriesArePagedByExpiryThenIdAfterTheKeysetCursor(): void
    {
        $repository = new DoctrineMotdRepository($this->entityManager);
        $createdAt = new DateTimeImmutable('2026-05-01 07:00:00');
        $repository->add('first', 'OperServ', MessageDelivery::NonInteractive, null, $createdAt, new DateTimeImmutable('2026-05-01 08:30:00'));
        $repository->add('active', 'OperServ', MessageDelivery::NonInteractive, null, $createdAt, new DateTimeImmutable('2026-05-01 12:00:00'));
        $repository->add('earliest', 'OperServ', MessageDelivery::NonInteractive, null, $createdAt, new DateTimeImmutable('2026-05-01 08:00:00'));
        $repository->add('tied', 'OperServ', MessageDelivery::Interactive, null, $createdAt, new DateTimeImmutable('2026-05-01 08:30:00'));
        $repository->add('permanent', 'OperServ', MessageDelivery::NonInteractive, null, $createdAt, null);
        $this->entityManager->clear();
        $now = new DateTimeImmutable('2026-05-01 10:00:00');

        $firstPage = $repository->findExpiredAt($now, null, 2);
        $secondPage = $repository->findExpiredAt($now, $firstPage[1], 2);

        self::assertSame(['earliest', 'first', 'tied'], self::texts($repository->findExpiredAt($now)));
        self::assertSame(['earliest', 'first'], self::texts($firstPage));
        self::assertSame(['tied'], self::texts($secondPage));
        self::assertSame([], $repository->findExpiredAt($now, $secondPage[0], 2));
    }

    #[Test]
    public function rejectsACursorThatHasNoExpiry(): void
    {
        $repository = new DoctrineMotdRepository($this->entityManager);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('MOTD expiry cursor must be an expired entry.');

        $repository->findExpiredAt(new DateTimeImmutable(), new MotdEntry(7, 'text', 'OperServ', MessageDelivery::NonInteractive, true, new DateTimeImmutable(), null, 0), 2);
    }

    /**
     * @param list<MotdEntry> $entries
     *
     * @return list<string>
     */
    private static function texts(array $entries): array
    {
        return array_map(static fn (MotdEntry $entry): string => $entry->text, $entries);
    }
}
