<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\NickServ\Doctrine;

use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\ValueObject\NickStatus;
use App\Infrastructure\NickServ\Doctrine\RegisteredNickDoctrineRepository;
use App\Tests\Integration\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RegisteredNickDoctrineRepository::class)]
#[Group('integration')]
final class RegisteredNickDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private RegisteredNickRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new RegisteredNickDoctrineRepository($this->entityManager);
    }

    #[Test]
    public function savePersistsNick(): void
    {
        $nick = $this->createRegisteredNick('TestUser', 'test@example.com');

        $this->repository->save($nick);
        $this->flushAndClear();

        $found = $this->repository->findByNick('TestUser');

        self::assertNotNull($found);
        self::assertSame('TestUser', $found->getNickname());
        self::assertSame('test@example.com', $found->getEmail());
    }

    #[Test]
    public function findByNickIsCaseInsensitive(): void
    {
        $nick = $this->createRegisteredNick('TestUser', 'test@example.com');
        $this->repository->save($nick);
        $this->flushAndClear();

        self::assertNotNull($this->repository->findByNick('TestUser'));
        self::assertNotNull($this->repository->findByNick('testuser'));
        self::assertNotNull($this->repository->findByNick('TESTUSER'));
    }

    #[Test]
    public function findByNickReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByNick('NonExistent'));
    }

    #[Test]
    public function findByIdReturnsNick(): void
    {
        $nick = $this->createRegisteredNick('TestUser', 'test@example.com');
        $this->repository->save($nick);
        $this->flushAndClear();

        $id = $nick->getId();
        $found = $this->repository->findById($id);

        self::assertNotNull($found);
        self::assertSame('TestUser', $found->getNickname());
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById(999999));
    }

    #[Test]
    public function findByEmailFindsByEmail(): void
    {
        $nick = $this->createRegisteredNick('TestUser', 'test@example.com');
        $this->repository->save($nick);
        $this->flushAndClear();

        $found = $this->repository->findByEmail('test@example.com');

        self::assertNotNull($found);
        self::assertSame('TestUser', $found->getNickname());
    }

    #[Test]
    public function findByEmailIsCaseInsensitive(): void
    {
        $nick = $this->createRegisteredNick('TestUser', 'test@example.com');
        $this->repository->save($nick);
        $this->flushAndClear();

        self::assertNotNull($this->repository->findByEmail('TEST@EXAMPLE.COM'));
        self::assertNotNull($this->repository->findByEmail('Test@Example.Com'));
    }

    #[Test]
    public function findByEmailReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByEmail('nonexistent@example.com'));
    }

    #[Test]
    public function findByVhostFindsByVhost(): void
    {
        $nick = $this->createRegisteredNick('TestUser', 'test@example.com');
        $nick->changeVhost('test.vhost.example');
        $this->repository->save($nick);
        $this->flushAndClear();

        $found = $this->repository->findByVhost('test.vhost.example');

        self::assertNotNull($found);
        self::assertSame('TestUser', $found->getNickname());
    }

    #[Test]
    public function findByVhostReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByVhost('nonexistent.vhost'));
    }

    #[Test]
    public function existsByNickReturnsTrueWhenExists(): void
    {
        $nick = $this->createRegisteredNick('TestUser', 'test@example.com');
        $this->repository->save($nick);
        $this->flushAndClear();

        self::assertTrue($this->repository->existsByNick('TestUser'));
        self::assertTrue($this->repository->existsByNick('testuser'));
    }

    #[Test]
    public function existsByNickReturnsFalseWhenNotExists(): void
    {
        self::assertFalse($this->repository->existsByNick('NonExistent'));
    }

    #[Test]
    public function deleteRemovesNick(): void
    {
        $nick = $this->createRegisteredNick('TestUser', 'test@example.com');
        $this->repository->save($nick);
        $this->entityManager->flush();

        $this->repository->delete($nick);
        $this->flushAndClear();

        self::assertNull($this->repository->findByNick('TestUser'));
    }

    #[Test]
    public function findByStatusReturnsMatchingNicks(): void
    {
        $registered = $this->createRegisteredNick('Registered', 'reg@example.com');
        $pending = RegisteredNick::createPending(
            'Pending',
            '$argon2id$v=19$m=65536,t=4,p=1$test$test',
            'pending@example.com',
            'en',
            new DateTimeImmutable('+24 hours')
        );

        $this->repository->save($registered);
        $this->repository->save($pending);
        $this->flushAndClear();

        $registeredNicks = $this->repository->findByStatus(NickStatus::Registered);
        $pendingNicks = $this->repository->findByStatus(NickStatus::Pending);

        self::assertCount(1, $registeredNicks);
        self::assertSame('Registered', $registeredNicks[0]->getNickname());

        self::assertCount(1, $pendingNicks);
        self::assertSame('Pending', $pendingNicks[0]->getNickname());
    }

    #[Test]
    public function allReturnsAllNicks(): void
    {
        $nick1 = $this->createRegisteredNick('User1', 'user1@example.com');
        $nick2 = $this->createRegisteredNick('User2', 'user2@example.com');

        $this->repository->save($nick1);
        $this->repository->save($nick2);
        $this->flushAndClear();

        $all = $this->repository->all();

        self::assertCount(2, $all);
    }

    private function createRegisteredNick(string $nickname, string $email): RegisteredNick
    {
        $nick = RegisteredNick::createPending(
            $nickname,
            '$argon2id$v=19$m=65536,t=4,p=1$test$test',
            $email,
            'en',
            new DateTimeImmutable('+24 hours')
        );

        $nick->activate();

        return $nick;
    }
}
