<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Irc;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\OperServ\Adapter\Out\Irc\NetworkGlineUserLookup;
use App\OperServ\Application\Port\Out\GlineUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NetworkGlineUserLookup::class)]
#[CoversClass(GlineUser::class)]
final class NetworkGlineUserLookupTest extends TestCase
{
    #[Test]
    public function translatesConnectedUserWithoutLeakingIrcView(): void
    {
        $network = $this->createStub(NetworkUserLookupPort::class);
        $network->method('findByNick')->willReturn(new SenderView('001AAA', 'Nick', 'ident', 'host.test', 'cloak', 'ip', true, false));
        $accounts = $this->createStub(NickAccountQuery::class);

        $user = new NetworkGlineUserLookup($network, $accounts)->findByNickname('Nick');

        self::assertNotNull($user);
        self::assertSame('Nick', $user->nickname);
        self::assertSame('ident', $user->ident);
        self::assertSame('host.test', $user->hostname);
    }

    #[Test]
    public function returnsNullForDisconnectedUser(): void
    {
        $network = $this->createStub(NetworkUserLookupPort::class);
        $network->method('findByNick')->willReturn(null);

        self::assertNull(new NetworkGlineUserLookup($network, $this->createStub(NickAccountQuery::class))->findByNickname('Missing'));
    }

    #[Test]
    public function delegatesAccountNicknameLookup(): void
    {
        $accounts = $this->createMock(NickAccountQuery::class);
        $accounts->expects(self::once())->method('findNicknameById')->with(42)->willReturn('AccountNick');

        self::assertSame(
            'AccountNick',
            new NetworkGlineUserLookup($this->createStub(NetworkUserLookupPort::class), $accounts)->findNicknameByAccountId(42),
        );
    }
}
