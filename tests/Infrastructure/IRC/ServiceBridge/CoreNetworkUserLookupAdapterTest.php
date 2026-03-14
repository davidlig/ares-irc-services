<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\ServiceBridge;

use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\ValueObject\Ident;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;
use App\Infrastructure\IRC\ServiceBridge\CoreNetworkUserLookupAdapter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(CoreNetworkUserLookupAdapter::class)]
final class CoreNetworkUserLookupAdapterTest extends TestCase
{
    private NetworkUserRepositoryInterface&MockObject $repository;

    private CoreNetworkUserLookupAdapter $adapter;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(NetworkUserRepositoryInterface::class);
        $this->adapter = new CoreNetworkUserLookupAdapter($this->repository);
    }

    #[Test]
    public function findByUidReturnsSenderViewWhenUserExists(): void
    {
        $user = $this->createNetworkUser(
            uid: '001ABCD',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'test.local',
            cloakedHost: 'test.local',
            modes: '+r',
        );

        $this->repository
            ->expects(self::once())
            ->method('findByUid')
            ->with(new Uid('001ABCD'))
            ->willReturn($user);

        $result = $this->adapter->findByUid('001ABCD');

        self::assertNotNull($result);
        self::assertSame('001ABCD', $result->uid);
        self::assertSame('TestUser', $result->nick);
        self::assertSame('test', $result->ident);
        self::assertSame('test.local', $result->hostname);
        self::assertTrue($result->isIdentified);
    }

    #[Test]
    public function findByUidReturnsNullWhenUserNotFound(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('findByUid')
            ->with(new Uid('999UNKNOWN'))
            ->willReturn(null);

        $result = $this->adapter->findByUid('999UNKNOWN');

        self::assertNull($result);
    }

    #[Test]
    public function findByNickReturnsSenderViewWhenUserExists(): void
    {
        $user = $this->createNetworkUser(
            uid: '001ABCD',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'test.local',
            cloakedHost: 'test.local',
            modes: '+r',
        );

        $this->repository
            ->expects(self::once())
            ->method('findByNick')
            ->with(new Nick('TestUser'))
            ->willReturn($user);

        $result = $this->adapter->findByNick('TestUser');

        self::assertNotNull($result);
        self::assertSame('TestUser', $result->nick);
    }

    #[Test]
    public function findByNickReturnsNullWhenUserNotFound(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('findByNick')
            ->with(new Nick('NonExistent'))
            ->willReturn(null);

        $result = $this->adapter->findByNick('NonExistent');

        self::assertNull($result);
    }

    #[Test]
    public function findByUidReturnsNullWhenUidInvalid(): void
    {
        $this->repository->expects(self::never())->method('findByUid');
        self::assertNull($this->adapter->findByUid(''));
    }

    #[Test]
    public function findByNickReturnsNullWhenNickInvalid(): void
    {
        $this->repository->expects(self::never())->method('findByNick');
        self::assertNull($this->adapter->findByNick(''));
    }

    #[Test]
    public function listConnectedUidsReturnsArrayOfUids(): void
    {
        $user1 = $this->createNetworkUser('001AAA', 'User1', 'u1', 'h1', 'c1', '+r');
        $user2 = $this->createNetworkUser('001BBB', 'User2', 'u2', 'h2', 'c2', '+i');

        $this->repository
            ->expects(self::once())
            ->method('all')
            ->willReturn([$user1, $user2]);

        $result = $this->adapter->listConnectedUids();

        self::assertCount(2, $result);
        self::assertSame('001AAA', $result[0]);
        self::assertSame('001BBB', $result[1]);
    }

    #[Test]
    public function fromNetworkUserMapsAllFields(): void
    {
        $user = $this->createNetworkUser(
            uid: '001ABCD',
            nick: 'TestUser',
            ident: 'testid',
            hostname: 'real.host.com',
            cloakedHost: 'cloaked.host.com',
            modes: '+roi',
            virtualHost: 'vhost.example.com',
        );

        $result = $this->adapter->fromNetworkUser($user);

        self::assertSame('001ABCD', $result->uid);
        self::assertSame('TestUser', $result->nick);
        self::assertSame('testid', $result->ident);
        self::assertSame('real.host.com', $result->hostname);
        self::assertSame('cloaked.host.com', $result->cloakedHost);
        self::assertTrue($result->isIdentified);
        self::assertTrue($result->isOper);
        self::assertSame('vhost.example.com', $result->displayHost);
        self::assertSame('001', $result->serverSid);
    }

    #[Test]
    public function fromNetworkUserHandlesOperMode(): void
    {
        $user = $this->createNetworkUser('001OPR', 'Oper', 'oper', 'oper.host', 'cloak', '+o');

        $result = $this->adapter->fromNetworkUser($user);

        self::assertTrue($result->isOper);
        self::assertFalse($result->isIdentified);
    }

    #[Test]
    public function fromNetworkUserHandlesUnidentifiedUser(): void
    {
        $user = $this->createNetworkUser('001GUEST', 'Guest', 'guest', 'guest.host', 'guest.local', '+i');

        $result = $this->adapter->fromNetworkUser($user);

        self::assertFalse($result->isIdentified);
        self::assertFalse($result->isOper);
    }

    private function createNetworkUser(
        string $uid,
        string $nick,
        string $ident,
        string $hostname,
        string $cloakedHost,
        string $modes,
        string $virtualHost = '*',
    ): NetworkUser {
        return new NetworkUser(
            uid: new Uid($uid),
            nick: new Nick($nick),
            ident: new Ident($ident),
            hostname: $hostname,
            cloakedHost: $cloakedHost,
            virtualHost: $virtualHost,
            modes: $modes,
            connectedAt: new DateTimeImmutable(),
            realName: 'Test User',
            serverSid: '001',
            ipBase64: 'dGVzdA==',
        );
    }
}
