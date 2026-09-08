<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Adapter\Out\Persistence\Doctrine\DoctrineMotdRepository;
use App\OperServ\Application\Port\Out\MotdEntry;
use App\OperServ\Domain\Entity\Motd;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

#[CoversClass(DoctrineMotdRepository::class)]
#[CoversClass(MotdEntry::class)]
final class DoctrineMotdRepositoryTest extends TestCase
{
    #[Test]
    public function persistsAndTranslatesNewMotd(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(Motd::class));
        $entityManager->expects(self::once())->method('flush');
        $createdAt = new DateTimeImmutable('2026-09-08T10:00:00+00:00');
        $expiresAt = new DateTimeImmutable('2026-09-09T10:00:00+00:00');

        $entry = new DoctrineMotdRepository($entityManager)->add('Welcome', 'NickServ', 'PRIVMSG', 42, $createdAt, $expiresAt);

        self::assertGreaterThan(0, $entry->id);
        self::assertSame('Welcome', $entry->text);
        self::assertSame('NickServ', $entry->botNickname);
        self::assertSame('PRIVMSG', $entry->messageType);
        self::assertTrue($entry->enabled);
        self::assertSame($createdAt, $entry->createdAt);
        self::assertSame($expiresAt, $entry->expiresAt);
        self::assertSame(0, $entry->shownCount);
        self::assertTrue($entry->isExpiredAt($expiresAt));
        self::assertFalse($entry->isExpiredAt($createdAt));
    }

    #[Test]
    public function findsAndTranslatesExistingMotdOrReturnsNull(): void
    {
        $motd = Motd::create('Welcome', 'NickServ', 'NOTICE');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('find')->with(Motd::class, self::logicalOr(7, 8))->willReturnOnConsecutiveCalls($motd, new stdClass());
        $adapter = new DoctrineMotdRepository($entityManager);

        self::assertNotNull($adapter->findById(7));
        self::assertNull($adapter->findById(8));
    }

    #[Test]
    public function findsAllInExpectedOrderAndFiltersUnexpectedValues(): void
    {
        $motd = Motd::create('Welcome', 'NickServ', 'NOTICE');
        $objectRepository = $this->createMock(EntityRepository::class);
        $objectRepository->expects(self::once())->method('findBy')->with([], ['createdAt' => 'DESC'])->willReturn([$motd, new stdClass()]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($objectRepository);

        $entries = new DoctrineMotdRepository($entityManager)->findAll();

        self::assertCount(1, $entries);
        self::assertSame('Welcome', $entries[0]->text);
    }

    #[Test]
    public function findsExpiredMotdsUsingBoundedQuery(): void
    {
        $at = new DateTimeImmutable('2026-09-08T10:00:00+00:00');
        $motd = Motd::create('Expired', 'NickServ', 'NOTICE', expiresAt: $at);
        $query = $this->createMock(Query::class);
        $query->expects(self::once())->method('setParameter')->with('at', $at)->willReturnSelf();
        $query->expects(self::once())->method('getResult')->willReturn([$motd, 'invalid']);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('createQuery')->with(self::stringContains('m.expiresAt <= :at'))->willReturn($query);

        $entries = new DoctrineMotdRepository($entityManager)->findExpiredAt($at);

        self::assertCount(1, $entries);
        self::assertSame('Expired', $entries[0]->text);
    }

    #[Test]
    public function removesExistingMotdAndIgnoresMissingOne(): void
    {
        $motd = Motd::create('Welcome', 'NickServ', 'NOTICE');
        $entry = $this->entry($motd);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('find')->with(Motd::class, $entry->id)->willReturnOnConsecutiveCalls(null, $motd);
        $entityManager->expects(self::once())->method('remove')->with($motd);
        $entityManager->expects(self::once())->method('flush');
        $adapter = new DoctrineMotdRepository($entityManager);

        $adapter->remove($entry);
        $adapter->remove($entry);
    }

    #[Test]
    public function rejectsPersistedMotdWithoutIdentifier(): void
    {
        $motd = Motd::create('Welcome', 'NickServ', 'NOTICE');
        new ReflectionProperty($motd, 'id')->setValue($motd, null);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($motd);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Persisted MOTD entry must have an identifier.');

        new DoctrineMotdRepository($entityManager)->findById(1);
    }

    private function entry(Motd $motd): MotdEntry
    {
        $id = $motd->getId();
        self::assertNotNull($id);

        return new MotdEntry($id, $motd->getText(), $motd->getBotNickname(), $motd->getMessageType(), true, $motd->getCreatedAt(), $motd->getExpiresAt(), 0);
    }
}
