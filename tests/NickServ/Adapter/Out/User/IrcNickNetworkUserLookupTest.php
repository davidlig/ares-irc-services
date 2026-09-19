<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\User;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\Out\User\IrcNetworkUserMapper;
use App\NickServ\Adapter\Out\User\IrcNickNetworkUserLookup;
use App\NickServ\Application\Model\NetworkUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcNickNetworkUserLookup::class)]
#[CoversClass(IrcNetworkUserMapper::class)]
#[CoversClass(NetworkUser::class)]
final class IrcNickNetworkUserLookupTest extends TestCase
{
    #[Test]
    public function mapsIrcUsersForUidAndNicknameLookups(): void
    {
        $sender = new SenderView('UID1', 'Alice', 'ident', 'host', 'cloak', 'ip', true, true, '001', 'display', 'ior');
        $lookup = $this->createMock(NetworkUserLookupPort::class);
        $lookup->expects(self::once())->method('findByUid')->with('UID1')->willReturn($sender);
        $lookup->expects(self::once())->method('findByNick')->with('Alice')->willReturn($sender);

        $adapter = new IrcNickNetworkUserLookup($lookup);

        $byUid = $adapter->findByUid('UID1');
        $byNick = $adapter->findByNick('Alice');
        self::assertInstanceOf(NetworkUser::class, $byUid);
        self::assertInstanceOf(NetworkUser::class, $byNick);
        self::assertSame('UID1', $byUid->uid);
        self::assertSame('display', $byNick->displayHost);
        self::assertTrue($byUid->isIdentified);
        self::assertTrue($byNick->isOper);
    }

    #[Test]
    public function preservesMissingUsersAsNull(): void
    {
        $lookup = $this->createStub(NetworkUserLookupPort::class);
        $adapter = new IrcNickNetworkUserLookup($lookup);

        self::assertNull($adapter->findByUid('missing'));
        self::assertNull($adapter->findByNick('missing'));
    }
}
