<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Network;

use App\ChanServ\Adapter\Out\Network\ChannelRankModeMapper;
use App\ChanServ\Adapter\Out\Network\IrcChannelRankNetworkQuery;
use App\ChanServ\Domain\ValueObject\ChannelRank;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Application\Port\In\NickAccountData;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\Shared\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Shared\Application\Port\ChannelModeSupportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcChannelRankNetworkQuery::class)]
final class IrcChannelRankNetworkQueryTest extends TestCase
{
    #[Test]
    public function itReturnsNullWhenTheNetworkChannelDoesNotExist(): void
    {
        $channels = $this->createStub(ChannelLookupPort::class);
        $channels->method('findByChannelName')->willReturn(null);

        self::assertNull($this->query($channels)->findChannel('#missing'));
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

        $registeredNicks = $this->createStub(NickAccountQuery::class);
        $registeredNicks->method('findAccountByNick')->willReturnCallback(
            static fn (string $nick): ?NickAccountData => match ($nick) {
                'Alice' => new NickAccountData(42, 'Alice', 'en'),
                'cHaNsErV' => new NickAccountData(99, 'ChanServ', 'en'),
                'PendingNick' => new NickAccountData(100, 'PendingNick', 'en', registered: false),
                default => null,
            },
        );

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
