<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Network;

use App\ChanServ\Adapter\Out\Network\IrcChannelEntryNetworkQuery;
use App\ChanServ\Application\Model\ChannelEntryMember;
use App\ChanServ\Application\Model\ChannelEntryNetworkState;
use App\Irc\Application\Port\In\BurstCompletePort;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcChannelEntryNetworkQuery::class)]
final class IrcChannelEntryNetworkQueryTest extends TestCase
{
    #[Test]
    public function reportsWhetherNetworkSynchronizationIsComplete(): void
    {
        $incomplete = $this->createStub(BurstCompletePort::class);
        $incomplete->method('isComplete')->willReturn(false);

        $complete = $this->createStub(BurstCompletePort::class);
        $complete->method('isComplete')->willReturn(true);

        self::assertFalse(new IrcChannelEntryNetworkQuery(
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(NetworkUserLookupPort::class),
            'ChanServ',
            $incomplete,
        )->synchronizationComplete());
        self::assertTrue(new IrcChannelEntryNetworkQuery(
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(NetworkUserLookupPort::class),
            'ChanServ',
            $complete,
        )->synchronizationComplete());
    }

    #[Test]
    public function mapsIndividualMemberAndDetectsChanservCaseInsensitively(): void
    {
        $users = $this->createStub(NetworkUserLookupPort::class);
        $users->method('findByUid')->willReturnMap([
            ['missing', null],
            ['001A', $this->user('001A', 'cHaNsErV', identified: true, operator: true)],
        ]);
        $query = new IrcChannelEntryNetworkQuery($this->createStub(ChannelLookupPort::class), $users, 'ChanServ', $this->createStub(BurstCompletePort::class));

        self::assertNull($query->findMember('missing'));
        self::assertMember(
            new ChannelEntryMember('001A', 'cHaNsErV', 'cHaNsErV!ident@example.test', true, true, true, 'cHaNsErV!ident@cloak.test'),
            $query->findMember('001A'),
        );
    }

    #[Test]
    public function mapsIndividualMemberByNickname(): void
    {
        $users = $this->createStub(NetworkUserLookupPort::class);
        $users->method('findByNick')->willReturnMap([
            ['missing', null],
            ['Alice', $this->user('001A', 'Alice', identified: true, operator: false)],
        ]);
        $query = new IrcChannelEntryNetworkQuery($this->createStub(ChannelLookupPort::class), $users, 'ChanServ', $this->createStub(BurstCompletePort::class));

        self::assertNull($query->findMemberByNick('missing'));
        self::assertMember(
            new ChannelEntryMember('001A', 'Alice', 'Alice!ident@example.test', true, false, false, 'Alice!ident@cloak.test'),
            $query->findMemberByNick('Alice'),
        );
    }

    #[Test]
    public function mapsOnlyResolvableNonEmptyChannelMembers(): void
    {
        $view = new ChannelView('#Test', '', null, 3, [
            ['uid' => '', 'roleLetter' => ''],
            ['uid' => 'missing', 'roleLetter' => ''],
            ['uid' => '001A', 'roleLetter' => ''],
        ]);
        $channels = $this->createStub(ChannelLookupPort::class);
        $channels->method('findByChannelName')->willReturnMap([
            ['#Missing', null],
            ['#Test', $view],
        ]);
        $users = $this->createStub(NetworkUserLookupPort::class);
        $users->method('findByUid')->willReturnMap([
            ['missing', null],
            ['001A', $this->user('001A', 'Member')],
        ]);
        $query = new IrcChannelEntryNetworkQuery($channels, $users, 'ChanServ', $this->createStub(BurstCompletePort::class));

        self::assertNull($query->findChannel('#Missing'));
        $state = $query->findChannel('#Test');
        self::assertInstanceOf(ChannelEntryNetworkState::class, $state);
        self::assertSame('#Test', $state->name);
        self::assertCount(1, $state->members);
        self::assertMember(
            new ChannelEntryMember('001A', 'Member', 'Member!ident@example.test', false, false, false, 'Member!ident@cloak.test'),
            $state->members[0],
        );
    }

    private static function assertMember(ChannelEntryMember $expected, ?ChannelEntryMember $actual): void
    {
        self::assertInstanceOf(ChannelEntryMember::class, $actual);
        self::assertSame($expected->uid, $actual->uid);
        self::assertSame($expected->nickname, $actual->nickname);
        self::assertSame($expected->userMask, $actual->userMask);
        self::assertSame($expected->identified, $actual->identified);
        self::assertSame($expected->operator, $actual->operator);
        self::assertSame($expected->service, $actual->service);
        self::assertSame($expected->displayMask, $actual->displayMask);
    }

    private function user(string $uid, string $nickname, bool $identified = false, bool $operator = false): SenderView
    {
        return new SenderView(
            uid: $uid,
            nick: $nickname,
            ident: 'ident',
            hostname: 'example.test',
            cloakedHost: 'cloak.test',
            ipBase64: 'ip',
            isIdentified: $identified,
            isOper: $operator,
        );
    }
}
