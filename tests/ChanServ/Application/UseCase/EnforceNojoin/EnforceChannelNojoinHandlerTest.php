<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\EnforceNojoin;

use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Model\ChannelEntryMember;
use App\ChanServ\Application\Model\ChannelEntryNetworkState;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelEntryNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\NojoinActions;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\UseCase\EnforceNojoin\EnforceChannelNojoin;
use App\ChanServ\Application\UseCase\EnforceNojoin\EnforceChannelNojoinHandler;
use App\ChanServ\Application\UseCase\EnforceNojoin\EnforceChannelNojoinHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceNojoin\SynchronizeAllChannelNojoinHandler;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Policy\ChannelAccessPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnforceChannelNojoin::class)]
#[CoversClass(EnforceChannelNojoinHandler::class)]
#[CoversClass(SynchronizeAllChannelNojoinHandler::class)]
final class EnforceChannelNojoinHandlerTest extends TestCase
{
    #[Test]
    public function joinSkipsUnavailableBlockedAndMissingMembers(): void
    {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturnOnConsecutiveCalls(null, $this->channel(blocked: true), $this->channel());
        $network = $this->createMock(ChannelEntryNetworkQuery::class);
        $network->expects(self::once())->method('findMember')->with('missing')->willReturn(null);
        $handler = $this->handler(channels: $channels, network: $network);

        self::assertSame(0, $handler->handle(new EnforceChannelNojoin('#Unavailable', 'missing')));
        self::assertSame(0, $handler->handle(new EnforceChannelNojoin('#Blocked', 'missing')));
        self::assertSame(0, $handler->handle(new EnforceChannelNojoin('#MissingUser', 'missing')));
    }

    #[Test]
    public function disabledNojoinDoesNotInspectMembers(): void
    {
        $levels = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levels->method('findByChannelAndKey')->willReturn(null);
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::never())->method('findAccountByNick');

        self::assertSame(0, $this->handler(levels: $levels, accounts: $accounts)->handleKnownChannel(
            $this->channel(),
            [$this->member('001A', 'Nick')],
        ));
    }

    #[Test]
    public function operatorAndChanservAreExempt(): void
    {
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::never())->method('findAccountByNick');

        self::assertSame(0, $this->handler(levels: $this->levels(100), accounts: $accounts)->handleKnownChannel(
            $this->channel(),
            [
                $this->member('001A', 'Oper', operator: true),
                $this->member('001B', 'ChanServ', service: true),
            ],
        ));
    }

    #[Test]
    public function unidentifiedRegisteredFounderIsKickedAtMinusOneUsingAccountLanguage(): void
    {
        $account = new ChanAccountView(10, 'Founder', 'es');
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountByNick')->willReturn($account);
        $access = $this->createMock(ChannelAccessRepositoryInterface::class);
        $access->expects(self::never())->method('findByChannelAndNick');
        $actions = $this->createMock(NojoinActions::class);
        $actions->expects(self::once())->method('kick')->with('#Test', '001A', 'Founder', -1, 0, 'es');

        self::assertSame(1, $this->handler(
            levels: $this->levels(0),
            access: $access,
            accounts: $accounts,
            actions: $actions,
        )->handleKnownChannel($this->channel(founderId: 10), [$this->member('001A', 'Founder')]));
    }

    #[Test]
    public function identifiedFounderHasImplicitFiveHundredAndIsNotKicked(): void
    {
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountByNick')->willReturn(new ChanAccountView(10, 'Founder', 'en'));
        $access = $this->createMock(ChannelAccessRepositoryInterface::class);
        $access->expects(self::never())->method('findByChannelAndNick');
        $actions = $this->createMock(NojoinActions::class);
        $actions->expects(self::never())->method('kick');

        self::assertSame(0, $this->handler(
            levels: $this->levels(499),
            access: $access,
            accounts: $accounts,
            actions: $actions,
        )->handleKnownChannel($this->channel(founderId: 10), [$this->member('001A', 'Founder', identified: true)]));
    }

    #[Test]
    public function identifiedAccessEntryBelowThresholdIsKicked(): void
    {
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountByNick')->willReturn(new ChanAccountView(20, 'Member', 'fr'));
        $access = $this->createStub(ChannelAccessRepositoryInterface::class);
        $access->method('findByChannelAndNick')->willReturn(new ChannelAccess(1, 20, 99));
        $actions = $this->createMock(NojoinActions::class);
        $actions->expects(self::once())->method('kick')->with('#Test', '001A', 'Member', 99, 100, 'fr');

        self::assertSame(1, $this->handler(
            levels: $this->levels(100),
            access: $access,
            accounts: $accounts,
            actions: $actions,
        )->handleKnownChannel($this->channel(), [$this->member('001A', 'Member', identified: true)]));
    }

    #[Test]
    public function missingAccountUsesDefaultLanguageAndNoAccess(): void
    {
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountByNick')->willReturn(null);
        $actions = $this->createMock(NojoinActions::class);
        $actions->expects(self::once())->method('kick')->with('#Test', '001A', 'Guest', -1, 1, 'tr');

        self::assertSame(1, $this->handler(
            levels: $this->levels(1),
            accounts: $accounts,
            actions: $actions,
            defaultLanguage: 'tr',
        )->handleKnownChannel($this->channel(), [$this->member('001A', 'Guest', identified: true)]));
    }

    #[Test]
    public function identifiedMemberWithoutStoredAccessMeetsZeroThreshold(): void
    {
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountByNick')->willReturn(new ChanAccountView(20, 'Member', 'en'));
        $access = $this->createStub(ChannelAccessRepositoryInterface::class);
        $access->method('findByChannelAndNick')->willReturn(null);
        $actions = $this->createMock(NojoinActions::class);
        $actions->expects(self::never())->method('kick');

        self::assertSame(0, $this->handler(
            levels: $this->levels(0),
            access: $access,
            accounts: $accounts,
            actions: $actions,
        )->handleKnownChannel($this->channel(), [$this->member('001A', 'Member', identified: true)]));
    }

    #[Test]
    public function joinNormalizesLookupAndKnownBlockedChannelStopsBeforeLevelLookup(): void
    {
        $channel = $this->channel();
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByChannelName')->with('#mixed')->willReturn($channel);
        $network = $this->createStub(ChannelEntryNetworkQuery::class);
        $network->method('findMember')->willReturn($this->member('001A', 'Guest'));
        $levels = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levels->expects(self::once())->method('findByChannelAndKey')->willReturn(new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, 0));

        self::assertSame(1, $this->handler(
            channels: $channels,
            levels: $levels,
            network: $network,
            actions: $this->createStub(NojoinActions::class),
        )->handle(new EnforceChannelNojoin('#MiXeD', '001A')));

        self::assertSame(0, $this->handler(levels: $levels)->handleKnownChannel($this->channel(blocked: true), []));
    }

    #[Test]
    public function fullSynchronizationSkipsBlockedAndUnavailableChannelsAndSumsEffects(): void
    {
        $active = $this->channel(name: '#Active');
        $missing = $this->channel(name: '#Missing');
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('listAll')->willReturn([$this->channel(name: '#Blocked', blocked: true), $missing, $active]);
        $state = new ChannelEntryNetworkState('#Active', [$this->member('001A', 'Guest')]);
        $network = $this->createMock(ChannelEntryNetworkQuery::class);
        $network->expects(self::exactly(2))->method('findChannel')->willReturnMap([
            ['#Missing', null],
            ['#Active', $state],
        ]);
        $enforcer = $this->createMock(EnforceChannelNojoinHandlerInterface::class);
        $enforcer->expects(self::once())->method('handleKnownChannel')->with($active, $state->members)->willReturn(3);

        self::assertSame(3, new SynchronizeAllChannelNojoinHandler($channels, $network, $enforcer)->handle());
    }

    private function handler(
        ?RegisteredChannelRepositoryInterface $channels = null,
        ?ChannelLevelRepositoryInterface $levels = null,
        ?ChannelAccessRepositoryInterface $access = null,
        ?ChanUserAccountPort $accounts = null,
        ?ChannelEntryNetworkQuery $network = null,
        ?NojoinActions $actions = null,
        string $defaultLanguage = 'en',
    ): EnforceChannelNojoinHandler {
        return new EnforceChannelNojoinHandler(
            $channels ?? $this->createStub(RegisteredChannelRepositoryInterface::class),
            $levels ?? $this->createStub(ChannelLevelRepositoryInterface::class),
            $access ?? $this->createStub(ChannelAccessRepositoryInterface::class),
            $accounts ?? $this->createStub(ChanUserAccountPort::class),
            $network ?? $this->createStub(ChannelEntryNetworkQuery::class),
            $actions ?? $this->createStub(NojoinActions::class),
            new ChannelAccessPolicy(),
            $defaultLanguage,
        );
    }

    private function levels(int $nojoin): ChannelLevelRepositoryInterface
    {
        $levels = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levels->method('findByChannelAndKey')->willReturn(new ChannelLevel(1, ChannelLevel::KEY_NOJOIN, $nojoin));

        return $levels;
    }

    private function channel(
        string $name = '#Test',
        bool $blocked = false,
        int $founderId = 10,
    ): RegisteredChannel {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn($name);
        $channel->method('isBlocked')->willReturn($blocked);
        $channel->method('isFounder')->willReturnCallback(static fn (int $nickId): bool => $founderId === $nickId);

        return $channel;
    }

    private function member(
        string $uid,
        string $nickname,
        bool $identified = false,
        bool $operator = false,
        bool $service = false,
    ): ChannelEntryMember {
        return new ChannelEntryMember($uid, $nickname, $nickname . '!ident@example.test', $identified, $operator, $service);
    }
}
