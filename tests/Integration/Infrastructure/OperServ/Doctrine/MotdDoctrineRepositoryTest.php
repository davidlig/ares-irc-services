<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\OperServ\Doctrine;

use App\Domain\OperServ\Entity\Motd;
use App\Infrastructure\OperServ\Doctrine\MotdDoctrineRepository;
use App\Tests\Integration\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(MotdDoctrineRepository::class)]
#[CoversClass(Motd::class)]
final class MotdDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private MotdDoctrineRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new MotdDoctrineRepository($this->entityManager);
    }

    #[Test]
    public function saveAndFindById(): void
    {
        $motd = Motd::create('Welcome to the network!', 'NickServ', 'PRIVMSG', 1);
        $this->repository->save($motd);

        $found = $this->repository->findById($motd->getId());
        self::assertNotNull($found);
        self::assertSame('Welcome to the network!', $found->getText());
        self::assertSame('NickServ', $found->getBotNickname());
        self::assertSame('PRIVMSG', $found->getMessageType());
        self::assertSame(0, $found->getShownCount());
        self::assertTrue($found->isEnabled());
    }

    #[Test]
    public function savePersistsShownCount(): void
    {
        $motd = Motd::create('Welcome to the network!', 'NickServ', 'PRIVMSG');
        $motd->recordShown();
        $motd->recordShown();

        $this->repository->save($motd);
        $this->entityManager->clear();

        $found = $this->repository->findById($motd->getId());
        self::assertNotNull($found);
        self::assertSame(2, $found->getShownCount());
    }

    #[Test]
    public function findAllReturnsEntries(): void
    {
        $this->repository->save(Motd::create('First', 'NickServ', 'PRIVMSG'));
        $this->repository->save(Motd::create('Second', 'ChanServ', 'NOTICE'));

        $all = $this->repository->findAll();
        self::assertCount(2, $all);
    }

    #[Test]
    public function findActiveReturnsEnabledAndNotExpired(): void
    {
        $this->repository->save(Motd::create('Active', 'NickServ', 'PRIVMSG'));

        $activeEntries = $this->repository->findActive();
        self::assertCount(1, $activeEntries);
    }

    #[Test]
    public function countActiveReturnsCorrectCount(): void
    {
        $this->repository->save(Motd::create('M1', 'NickServ', 'PRIVMSG'));
        $this->repository->save(Motd::create('M2', 'ChanServ', 'NOTICE'));

        self::assertSame(2, $this->repository->countActive());
    }

    #[Test]
    public function removeDeletesEntry(): void
    {
        $motd = Motd::create('Temp', 'NickServ', 'PRIVMSG');
        $this->repository->save($motd);
        $id = $motd->getId();

        $this->repository->remove($motd);

        self::assertNull($this->repository->findById($id));
    }

    #[Test]
    public function findByIdReturnsNullForNonexistent(): void
    {
        self::assertNull($this->repository->findById(99999));
    }

    #[Test]
    public function deleteByNickIdRemovesMatchingEntries(): void
    {
        $motd1 = Motd::create('M1', 'NickServ', 'PRIVMSG', 42);
        $motd2 = Motd::create('M2', 'ChanServ', 'NOTICE', 42);
        $motd3 = Motd::create('M3', 'OperServ', 'PRIVMSG', 99);

        $this->repository->save($motd1);
        $this->repository->save($motd2);
        $this->repository->save($motd3);

        $id1 = $motd1->getId();
        $id2 = $motd2->getId();
        $id3 = $motd3->getId();

        $this->repository->deleteByNickId(42);

        $this->entityManager->clear();

        self::assertNull($this->repository->findById($id1));
        self::assertNull($this->repository->findById($id2));
        self::assertNotNull($this->repository->findById($id3));
    }

    #[Test]
    public function findExpiredReturnsPastExpiration(): void
    {
        $expired = Motd::create('Expired', 'Bot1', 'PRIVMSG', null, new DateTimeImmutable('-1 hour'));
        $active = Motd::create('Active', 'Bot2', 'NOTICE', null, new DateTimeImmutable('+1 hour'));
        $permanent = Motd::create('Permanent', 'Bot3', 'PRIVMSG', null, null);

        $this->repository->save($expired);
        $this->repository->save($active);
        $this->repository->save($permanent);

        $result = $this->repository->findExpired();

        self::assertCount(1, $result);
        self::assertSame('Expired', $result[0]->getText());
    }
}
