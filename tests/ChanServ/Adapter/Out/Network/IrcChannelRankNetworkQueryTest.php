<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Network;

use App\ChanServ\Adapter\Out\Network\ChannelRankModeMapper;
use App\ChanServ\Adapter\Out\Network\IrcChannelRankNetworkQuery;
use App\ChanServ\Domain\ValueObject\ChannelRank;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Application\Port\In\NickAccountData;
use App\NickServ\Application\Port\In\NickAccountQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcChannelRankNetworkQuery::class)]
final class IrcChannelRankNetworkQueryTest extends TestCase
{
    #[Test]
    public function itResolvesOnlyTheRequestedMemberForSingleMemberEvents(): void
    {
        $channels = $this->createMock(ChannelLookupPort::class);
        $channels->expects(self::once())->method('findMember')->with('#test', 'U1')->willReturn(new ChannelView(
            name: '#test',
            modes: '',
            topic: null,
            memberCount: 3,
            members: [['uid' => 'U1', 'roleLetter' => 'o']],
        ));
        $channels->expects(self::never())->method('findByChannelName');
        $users = $this->createStub(NetworkUserLookupPort::class);
        $users->method('findByUid')->willReturn(new SenderView('U1', 'Alice', 'a', 'host', 'cloak', '', true));
        $accounts = $this->createStub(NickAccountQuery::class);
        $accounts->method('findAccountByNick')->willReturn(new NickAccountData(42, 'Alice', 'en'));
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getSupportedPrefixModes')->willReturn(['o']);
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $provider->method('getSupport')->willReturn($support);

        $result = new IrcChannelRankNetworkQuery($channels, $users, $accounts, $provider, new ChannelRankModeMapper(), 'ChanServ')
            ->findMember('#test', 'U1');

        self::assertNotNull($result);
        self::assertCount(1, $result->members);
        self::assertSame(42, $result->members[0]->registeredNickId);
        self::assertSame([ChannelRank::Operator], $result->supportedRanks);
    }

    #[Test]
    public function itReturnsNullWhenTheNetworkChannelDoesNotExist(): void
    {
        $channels = $this->createStub(ChannelLookupPort::class);
        $channels->method('findByChannelName')->willReturn(null);

        self::assertNull($this->query($channels)->findChannel('#missing'));
        self::assertNull($this->query($channels)->findMember('#missing', 'U1'));
    }

    #[Test]
    public function itMapsOnlyMembersIdentitiesAndSupportedRanks(): void
    {
        $channels = $this->createStub(ChannelLookupPort::class);
        $channels->method('findByChannelName')->willReturn(new ChannelView(
            name: '#Case',
            modes: '+nkKrP',
            topic: null,
            memberCount: 5,
            members: [
                ['uid' => '', 'roleLetter' => 'o'],
                ['uid' => 'U1', 'roleLetter' => 'o', 'prefixLetters' => ['o', 'v', 'o']],
                ['uid' => 'Alias', 'roleLetter' => 'v'],
                ['uid' => 'Pending', 'roleLetter' => 'q'],
                ['uid' => 'Missing', 'roleLetter' => 'o'],
            ],
            modeParams: ['k' => 'secret'],
        ));

        $identified = new SenderView('U1', 'Alice', 'a', 'host', 'cloak', '', true);
        $unidentifiedService = new SenderView('U2', 'cHaNsErV', 's', 'host', 'cloak', '', false);
        $pendingUser = new SenderView('U3', 'PendingNick', 'p', 'host', 'cloak', '', true);
        $users = $this->createStub(NetworkUserLookupPort::class);
        $users->method('findByUid')->willReturnCallback(
            static fn (string $uid): ?SenderView => match ($uid) {
                'U1' => $identified,
                'Pending' => $pendingUser,
                default => null,
            },
        );
        $users->method('findByNick')->willReturnCallback(
            static fn (string $nick): ?SenderView => 'Alias' === $nick ? $unidentifiedService : null,
        );

        $registeredNicks = $this->createMock(NickAccountQuery::class);
        $registeredNicks->expects(self::once())->method('findAccountsByNicks')
            ->with(['Alice', 'cHaNsErV', 'PendingNick'])->willReturn([
                'alice' => new NickAccountData(42, 'Alice', 'en'),
                'chanserv' => new NickAccountData(99, 'ChanServ', 'en'),
                'pendingnick' => new NickAccountData(100, 'PendingNick', 'en', registered: false),
            ]);

        $support = $this->createMock(ChannelModeSupportInterface::class);
        $support->expects(self::once())->method('getSupportedPrefixModes')->willReturn(['q', 'o', 'x', 'v']);
        $support->expects(self::never())->method('getChannelSettingModesUnsetWithoutParam');
        $support->expects(self::never())->method('getChannelSettingModesUnsetWithParam');
        $support->expects(self::never())->method('getChannelSettingModesWithParamOnSet');
        $supportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $supportProvider->method('getSupport')->willReturn($support);

        $result = new IrcChannelRankNetworkQuery(
            $channels,
            $users,
            $registeredNicks,
            $supportProvider,
            new ChannelRankModeMapper(),
            'ChanServ',
        )->findChannel('#Case');

        self::assertNotNull($result);
        self::assertSame('#Case', $result->name);
        self::assertSame([ChannelRank::Owner, ChannelRank::Operator, ChannelRank::Voice], $result->supportedRanks);
        self::assertCount(3, $result->members);
        self::assertSame('U1', $result->members[0]->uid);
        self::assertTrue($result->members[0]->identified);
        self::assertSame(42, $result->members[0]->registeredNickId);
        self::assertSame([ChannelRank::Operator, ChannelRank::Voice], $result->members[0]->currentRanks);
        self::assertSame('U2', $result->members[1]->uid);
        self::assertFalse($result->members[1]->identified);
        self::assertTrue($result->members[1]->service);
        self::assertSame(99, $result->members[1]->registeredNickId);
        self::assertSame([ChannelRank::Voice], $result->members[1]->currentRanks);
        self::assertTrue($result->members[2]->identified);
        self::assertNull($result->members[2]->registeredNickId);
        self::assertSame([ChannelRank::Owner], $result->members[2]->currentRanks);
    }

    private function query(ChannelLookupPort $channels): IrcChannelRankNetworkQuery
    {
        return new IrcChannelRankNetworkQuery(
            $channels,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickAccountQuery::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            new ChannelRankModeMapper(),
            'ChanServ',
        );
    }
}
