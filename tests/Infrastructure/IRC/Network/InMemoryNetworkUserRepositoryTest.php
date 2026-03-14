<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Network;

use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\ValueObject\Ident;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;
use App\Infrastructure\IRC\Network\InMemoryNetworkUserRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryNetworkUserRepository::class)]
final class InMemoryNetworkUserRepositoryTest extends TestCase
{
    private InMemoryNetworkUserRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryNetworkUserRepository();
    }

    private function createUser(string $uid, string $nick): NetworkUser
    {
        return new NetworkUser(
            uid: new Uid($uid),
            nick: new Nick($nick),
            ident: new Ident('user'),
            hostname: 'hostname',
            cloakedHost: 'cloaked.host',
            virtualHost: 'vhost',
            modes: '+i',
            connectedAt: new DateTimeImmutable(),
            realName: 'Test User',
            serverSid: '001',
            ipBase64: 'dGVzdA==',
        );
    }

    #[Test]
    public function addStoresUser(): void
    {
        $user = $this->createUser('001ABCD', 'TestUser');

        $this->repository->add($user);

        self::assertSame($user, $this->repository->findByUid(new Uid('001ABCD')));
    }

    #[Test]
    public function findByUidReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByUid(new Uid('NONEXISTENT')));
    }

    #[Test]
    public function findByNickFindsUserCaseInsensitive(): void
    {
        $user = $this->createUser('001ABCD', 'TestUser');
        $this->repository->add($user);

        self::assertSame($user, $this->repository->findByNick(new Nick('TestUser')));
        self::assertSame($user, $this->repository->findByNick(new Nick('testuser')));
        self::assertSame($user, $this->repository->findByNick(new Nick('TESTUSER')));
    }

    #[Test]
    public function findByNickReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByNick(new Nick('NonExistent')));
    }

    #[Test]
    public function removeByUidRemovesUser(): void
    {
        $user = $this->createUser('001ABCD', 'TestUser');
        $this->repository->add($user);

        $this->repository->removeByUid(new Uid('001ABCD'));

        self::assertNull($this->repository->findByUid(new Uid('001ABCD')));
        self::assertNull($this->repository->findByNick(new Nick('TestUser')));
    }

    #[Test]
    public function removeByUidDoesNothingWhenNotFound(): void
    {
        $this->repository->removeByUid(new Uid('NONEXISTENT'));

        self::assertNull($this->repository->findByUid(new Uid('NONEXISTENT')));
    }

    #[Test]
    public function updateNickUpdatesUserNickAndIndex(): void
    {
        $user = $this->createUser('001ABCD', 'OldNick');
        $this->repository->add($user);

        $this->repository->updateNick(new Uid('001ABCD'), new Nick('OldNick'), new Nick('NewNick'));

        self::assertSame($user, $this->repository->findByUid(new Uid('001ABCD')));
        self::assertSame($user, $this->repository->findByNick(new Nick('NewNick')));
        self::assertNull($this->repository->findByNick(new Nick('OldNick')));
    }

    #[Test]
    public function updateNickDoesNothingWhenUidNotFound(): void
    {
        $this->repository->updateNick(new Uid('NONEXISTENT'), new Nick('Old'), new Nick('New'));

        self::assertNull($this->repository->findByUid(new Uid('NONEXISTENT')));
    }

    #[Test]
    public function allReturnsAllUsers(): void
    {
        $user1 = $this->createUser('001AAAA', 'User1');
        $user2 = $this->createUser('001BBBB', 'User2');
        $this->repository->add($user1);
        $this->repository->add($user2);

        $all = $this->repository->all();

        self::assertCount(2, $all);
        self::assertContains($user1, $all);
        self::assertContains($user2, $all);
    }

    #[Test]
    public function allReturnsEmptyArrayWhenNoUsers(): void
    {
        self::assertSame([], $this->repository->all());
    }

    #[Test]
    public function countReturnsNumberOfUsers(): void
    {
        self::assertSame(0, $this->repository->count());

        $this->repository->add($this->createUser('001AAAA', 'User1'));
        self::assertSame(1, $this->repository->count());

        $this->repository->add($this->createUser('001BBBB', 'User2'));
        self::assertSame(2, $this->repository->count());
    }

    #[Test]
    public function addOverwritesExistingUser(): void
    {
        $user1 = $this->createUser('001ABCD', 'TestUser');
        $user2 = $this->createUser('001ABCD', 'TestUser');

        $this->repository->add($user1);
        $this->repository->add($user2);

        self::assertSame($user2, $this->repository->findByUid(new Uid('001ABCD')));
        self::assertSame(1, $this->repository->count());
    }
}
