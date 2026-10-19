<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\ManageAkick;

use App\ChanServ\Application\Model\ChannelEntryMember;
use App\ChanServ\Application\Model\ChannelEntryNetworkState;
use App\ChanServ\Application\Port\Out\AkickActions;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelEntryNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Application\UseCase\EnforceAkick\AkickUseRecorder;
use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkickHandler;
use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkickHandlerInterface;
use App\ChanServ\Application\UseCase\ManageAkick\ChannelAkickEntryView;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkick;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkickAction;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkickHandler;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkickOutcome;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkickResult;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelAkick;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\InsufficientAccessException;
use App\ChanServ\Domain\Policy\AkickAdditionPolicy;
use App\ChanServ\Domain\Policy\AkickMatchPolicy;
use App\ChanServ\Domain\Policy\AkickProtectionPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ManageChannelAkick::class)]
#[CoversClass(ManageChannelAkickAction::class)]
#[CoversClass(ManageChannelAkickHandler::class)]
#[CoversClass(ManageChannelAkickOutcome::class)]
#[CoversClass(ManageChannelAkickResult::class)]
final class ManageChannelAkickHandlerTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-20 12:00:00 UTC');
    }

    #[Test]
    public function setChangesTheReasonAndKeepsTheAuthor(): void
    {
        $akick = ChannelAkick::create($this->now, 1, 7, '*!*@bad.host', 'Old reason');
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('findByChannelAndMask')->willReturn($akick);

        $saved = null;
        $akicks->method('save')->willReturnCallback(static function (ChannelAkick $entity) use (&$saved): void {
            $saved = $entity;
        });

        $result = $this->handler($akicks)->handle($this->command(
            ManageChannelAkickAction::Set,
            item: '*!*@bad.host',
            reason: 'New reason',
        ));

        self::assertSame(ManageChannelAkickOutcome::ReasonUpdated, $result->outcome);
        self::assertSame('*!*@bad.host', $result->mask);
        self::assertSame('New reason', $result->reason);
        self::assertSame($akick, $saved);
        self::assertSame('New reason', $akick->getReason());
        self::assertSame(7, $akick->getCreatorNickId(), 'The original author must be preserved.');
    }

    #[Test]
    public function setSelectsTheEntryByItsNumberInTheList(): void
    {
        $first = ChannelAkick::create($this->now, 1, 7, '*!*@one.host', 'One');
        $second = ChannelAkick::create($this->now, 1, 7, '*!*@two.host', 'Two');
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$first, $second]);

        $result = $this->handler($akicks)->handle($this->command(
            ManageChannelAkickAction::Set,
            item: '2',
            reason: 'Changed',
        ));

        self::assertSame(ManageChannelAkickOutcome::ReasonUpdated, $result->outcome);
        self::assertSame('*!*@two.host', $result->mask);
        self::assertSame('Changed', $second->getReason());
        self::assertSame('One', $first->getReason());
    }

    #[Test]
    public function setWithoutReasonClearsTheStoredReason(): void
    {
        $akick = ChannelAkick::create($this->now, 1, 7, '*!*@bad.host', 'Old reason');
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('findByChannelAndMask')->willReturn($akick);

        $result = $this->handler($akicks)->handle($this->command(
            ManageChannelAkickAction::Set,
            item: '*!*@bad.host',
        ));

        self::assertSame(ManageChannelAkickOutcome::ReasonUpdated, $result->outcome);
        self::assertNull($result->reason);
        self::assertNull($akick->getReason());
    }

    #[Test]
    public function setOnAnUnknownEntryReportsThatItWasNotFound(): void
    {
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('findByChannelAndMask')->willReturn(null);

        $result = $this->handler($akicks)->handle($this->command(
            ManageChannelAkickAction::Set,
            item: '*!*@missing.host',
            reason: 'New reason',
        ));

        self::assertSame(ManageChannelAkickOutcome::EntryNotFound, $result->outcome);
        self::assertSame('*!*@missing.host', $result->mask);
    }

    #[Test]
    public function setWithAnOverlongReasonIsRefused(): void
    {
        $akick = ChannelAkick::create($this->now, 1, 7, '*!*@bad.host', 'Old reason');
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('findByChannelAndMask')->willReturn($akick);

        $result = $this->handler($akicks)->handle($this->command(
            ManageChannelAkickAction::Set,
            item: '*!*@bad.host',
            reason: str_repeat('x', ChannelAkick::MAX_REASON_LENGTH + 1),
        ));

        self::assertSame(ManageChannelAkickOutcome::InvalidReason, $result->outcome);
        self::assertSame('Old reason', $akick->getReason());
    }

    #[Test]
    public function setWithoutAnEntrySelectorIsAnInvalidRequest(): void
    {
        $result = $this->handler($this->createStub(ChannelAkickRepositoryInterface::class))->handle(
            $this->command(ManageChannelAkickAction::Set, validRequest: false),
        );

        self::assertSame(ManageChannelAkickOutcome::InvalidRequest, $result->outcome);
    }

    #[Test]
    public function clearRemovesEveryEntryOfTheChannel(): void
    {
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('deleteByChannelId')->willReturn(3);

        $result = $this->handler($akicks)->handle($this->command(ManageChannelAkickAction::Clear));

        self::assertSame(ManageChannelAkickOutcome::Cleared, $result->outcome);
        self::assertSame(3, $result->count);
    }

    #[Test]
    public function clearIsRefusedBelowTheAkickChangeLevel(): void
    {
        $akicks = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akicks->expects(self::never())->method('deleteByChannelId');

        $this->expectException(InsufficientAccessException::class);

        $this->handler($akicks, founder: false, accessLevel: 450)->handle($this->command(
            ManageChannelAkickAction::Clear,
        ));
    }

    #[Test]
    public function clearIsAllowedForAFounder(): void
    {
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('deleteByChannelId')->willReturn(1);

        $result = $this->handler($akicks)->handle($this->command(ManageChannelAkickAction::Clear));

        self::assertSame(ManageChannelAkickOutcome::Cleared, $result->outcome);
        self::assertSame(1, $result->count);
    }

    #[Test]
    public function countReturnsTheNumberOfEntries(): void
    {
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('countByChannel')->willReturn(7);

        $result = $this->handler($akicks)->handle($this->command(ManageChannelAkickAction::Count));

        self::assertSame(ManageChannelAkickOutcome::Counted, $result->outcome);
        self::assertSame(7, $result->count);
    }

    #[Test]
    public function checkReturnsTheEntriesMatchingTheConnectedUser(): void
    {
        $matching = ChannelAkick::create($this->now, 1, 7, '*!*@bad.host', 'Spammer');
        $other = ChannelAkick::create($this->now, 1, 7, '*!*@good.host', 'Other');
        $expired = ChannelAkick::create($this->now, 1, 7, '*!*@bad.host', 'Expired', new DateTimeImmutable('-1 day'));
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$matching, $other, $expired]);

        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findNicknameById')->willReturnMap([[7, 'Author'], [9, null]]);

        $result = $this->handler($akicks, accounts: $accounts)->handle($this->command(
            ManageChannelAkickAction::Check,
            nickname: 'BadUser',
            userMask: 'BadUser!ident@bad.host',
        ));

        self::assertSame(ManageChannelAkickOutcome::Checked, $result->outcome);
        self::assertSame('BadUser', $result->nickname);
        self::assertCount(1, $result->entries);
        self::assertSame('*!*@bad.host', $result->entries[0]->mask);
        self::assertSame('Spammer', $result->entries[0]->reason);
        self::assertSame('Author', $result->entries[0]->creatorNickname);
    }

    #[Test]
    public function checkRefusesAnUnknownNickname(): void
    {
        $result = $this->handler($this->createStub(ChannelAkickRepositoryInterface::class))->handle($this->command(
            ManageChannelAkickAction::Check,
            nickname: 'Ghost',
        ));

        self::assertSame(ManageChannelAkickOutcome::NicknameNotConnected, $result->outcome);
        self::assertSame('Ghost', $result->nickname);
    }

    #[Test]
    public function checkWithoutANicknameIsAnInvalidRequest(): void
    {
        $result = $this->handler($this->createStub(ChannelAkickRepositoryInterface::class))->handle($this->command(
            ManageChannelAkickAction::Check,
            validRequest: false,
        ));

        self::assertSame(ManageChannelAkickOutcome::InvalidRequest, $result->outcome);
    }

    #[Test]
    public function aFounderEquivalentActorBypassesTheChannelLevel(): void
    {
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('countByChannel')->willReturn(2);

        $command = new ManageChannelAkick(
            channelName: '#Test',
            action: ManageChannelAkickAction::Count,
            actorNickId: 1,
            founderEquivalent: true,
            performedBy: 'Oper',
            performedByIp: '127.0.0.1',
            performedByHost: 'ident@host',
            now: $this->now,
        );

        $result = $this->handler($akicks, founder: false)->handle($command);

        self::assertSame(ManageChannelAkickOutcome::Counted, $result->outcome);
        self::assertSame(2, $result->count);
    }

    #[Test]
    public function checkNeverMatchesAnIrcOperator(): void
    {
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([
            ChannelAkick::create($this->now, 1, 7, '*!*@bad.host', 'Spammer'),
        ]);

        $result = $this->handler($akicks)->handle($this->command(
            ManageChannelAkickAction::Check,
            nickname: 'Oper',
            userMask: 'Oper!ident@bad.host',
            targetIsOperator: true,
        ));

        self::assertSame(ManageChannelAkickOutcome::Checked, $result->outcome);
        self::assertSame([], $result->entries);
    }

    #[Test]
    public function enforceAppliesTheListToTheMembersConnectedNow(): void
    {
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([
            ChannelAkick::create($this->now, 1, 7, '*!*@bad.host', 'Spammer'),
        ]);

        $members = [new ChannelEntryMember('UID2', 'BadUser', 'BadUser!ident@bad.host', true, false, false)];
        $network = $this->createStub(ChannelEntryNetworkQuery::class);
        $network->method('findChannel')->willReturn(new ChannelEntryNetworkState('#Test', $members));

        $banned = [];
        $actions = $this->createStub(AkickActions::class);
        $actions->method('banAndKick')->willReturnCallback(
            static function (string $channelName, string $uid, string $mask, string $reason) use (&$banned): void {
                $banned[] = [$channelName, $uid, $mask, $reason];
            },
        );

        $result = $this->handler($akicks, network: $network, actions: $actions)->handle($this->command(
            ManageChannelAkickAction::Enforce,
        ));

        self::assertSame(ManageChannelAkickOutcome::Enforced, $result->outcome);
        self::assertSame(1, $result->count);
        self::assertSame([['#Test', 'UID2', '*!*@bad.host', 'Spammer']], $banned);
    }

    #[Test]
    public function enforceExpelsNobodyWhenTheChannelIsNotOnTheNetwork(): void
    {
        $network = $this->createStub(ChannelEntryNetworkQuery::class);
        $network->method('findChannel')->willReturn(null);

        $actions = $this->createMock(AkickActions::class);
        $actions->expects(self::never())->method('banAndKick');

        $result = $this->handler(
            $this->createStub(ChannelAkickRepositoryInterface::class),
            network: $network,
            actions: $actions,
        )->handle($this->command(ManageChannelAkickAction::Enforce));

        self::assertSame(ManageChannelAkickOutcome::Enforced, $result->outcome);
        self::assertSame(0, $result->count);
    }

    #[Test]
    public function viewReturnsTheEntriesWithTheirDetails(): void
    {
        $akick = ChannelAkick::create($this->now, 1, 7, '*!*@bad.host', 'Spammer');
        $akick->recordUse($this->now);
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$akick]);

        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findNicknameById')->willReturnMap([[7, 'Author']]);

        $result = $this->handler($akicks, accounts: $accounts)->handle($this->command(ManageChannelAkickAction::View));

        self::assertSame(ManageChannelAkickOutcome::Viewed, $result->outcome);
        self::assertCount(1, $result->entries);
        self::assertSame('*!*@bad.host', $result->entries[0]->mask);
        self::assertSame('Spammer', $result->entries[0]->reason);
        self::assertSame('Author', $result->entries[0]->creatorNickname);
        self::assertSame($this->now, $result->entries[0]->createdAt);
        self::assertSame(1, $result->entries[0]->uses);
        self::assertSame($this->now, $result->entries[0]->lastUsedAt);
    }

    #[Test]
    public function viewOnAnEmptyChannelReportsThatThereAreNoEntries(): void
    {
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([]);

        $result = $this->handler($akicks)->handle($this->command(ManageChannelAkickAction::View));

        self::assertSame(ManageChannelAkickOutcome::ViewEmpty, $result->outcome);
    }

    #[Test]
    public function viewKeepsTheEntryWhoseAuthorIsGoneWithoutANickname(): void
    {
        $akick = ChannelAkick::create($this->now, 1, 7, '*!*@bad.host', 'Spammer');
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$akick]);

        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findNicknameById')->willReturn(null);

        $result = $this->handler($akicks, accounts: $accounts)->handle($this->command(ManageChannelAkickAction::View));

        self::assertSame(ManageChannelAkickOutcome::Viewed, $result->outcome);
        self::assertNull($result->entries[0]->creatorNickname);
    }

    #[Test]
    public function lastUseOrdersTheEntriesByTheirLastUseWithTheNeverUsedOnesLast(): void
    {
        $never = ChannelAkick::create($this->now, 1, 7, '*!*@never.host', 'Never');
        $older = ChannelAkick::create($this->now, 1, 7, '*!*@older.host', 'Older');
        $older->recordUse(new DateTimeImmutable('2026-10-20 09:00:00 UTC'));
        $newer = ChannelAkick::create($this->now, 1, 7, '*!*@newer.host', 'Newer');
        $newer->recordUse(new DateTimeImmutable('2026-10-20 11:00:00 UTC'));

        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$never, $older, $newer]);

        $result = $this->handler($akicks)->handle($this->command(ManageChannelAkickAction::LastUse));

        self::assertSame(ManageChannelAkickOutcome::LastUsed, $result->outcome);
        self::assertSame(
            ['*!*@newer.host', '*!*@older.host', '*!*@never.host'],
            array_map(static fn (ChannelAkickEntryView $entry): string => $entry->mask, $result->entries),
        );
        self::assertSame(0, $result->entries[2]->uses);
        self::assertNull($result->entries[2]->lastUsedAt);
    }

    #[Test]
    public function lastUseKeepsTheRelativeOrderOfTheEntriesThatWereNeverUsed(): void
    {
        $neverOne = ChannelAkick::create($this->now, 1, 7, '*!*@never-one.host');
        $neverTwo = ChannelAkick::create($this->now, 1, 7, '*!*@never-two.host');
        $used = ChannelAkick::create($this->now, 1, 7, '*!*@used.host');
        $used->recordUse($this->now);

        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$neverOne, $neverTwo, $used]);

        $result = $this->handler($akicks)->handle($this->command(ManageChannelAkickAction::LastUse));

        self::assertSame(ManageChannelAkickOutcome::LastUsed, $result->outcome);
        self::assertSame(
            ['*!*@used.host', '*!*@never-one.host', '*!*@never-two.host'],
            array_map(static fn (ChannelAkickEntryView $entry): string => $entry->mask, $result->entries),
        );
    }

    #[Test]
    public function lastUseOnAnEmptyChannelReportsThatThereAreNoEntries(): void
    {
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([]);

        $result = $this->handler($akicks)->handle($this->command(ManageChannelAkickAction::LastUse));

        self::assertSame(ManageChannelAkickOutcome::LastUseEmpty, $result->outcome);
    }

    #[Test]
    public function theEnforcementOfTheListRecordsTheUseOfTheEntryThatCaughtAMember(): void
    {
        $akick = ChannelAkick::create($this->now, 1, 7, '*!*@bad.host', 'Spammer');
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$akick]);

        $members = [new ChannelEntryMember('UID2', 'BadUser', 'BadUser!ident@bad.host', true, false, false)];
        $network = $this->createStub(ChannelEntryNetworkQuery::class);
        $network->method('synchronizationComplete')->willReturn(true);
        $network->method('findChannel')->willReturn(new ChannelEntryNetworkState('#Test', $members));

        $result = $this->handler($akicks, network: $network)->handle($this->command(ManageChannelAkickAction::List));

        self::assertSame(ManageChannelAkickOutcome::Listed, $result->outcome);
        self::assertSame(1, $akick->getUses());
        self::assertSame($this->now, $akick->getLastUsedAt());
    }

    #[Test]
    public function anUnknownActionIsReported(): void
    {
        $result = $this->handler($this->createStub(ChannelAkickRepositoryInterface::class))->handle(
            $this->command(ManageChannelAkickAction::Unknown),
        );

        self::assertSame(ManageChannelAkickOutcome::UnknownAction, $result->outcome);
    }

    private function command(
        ManageChannelAkickAction $action,
        ?string $item = null,
        ?string $reason = null,
        ?string $nickname = null,
        ?string $userMask = null,
        bool $targetIsOperator = false,
        bool $validRequest = true,
    ): ManageChannelAkick {
        return new ManageChannelAkick(
            channelName: '#Test',
            action: $action,
            actorNickId: 1,
            founderEquivalent: false,
            performedBy: 'Founder',
            performedByIp: '127.0.0.1',
            performedByHost: 'ident@host',
            now: $this->now,
            item: $item,
            reason: $reason,
            validRequest: $validRequest,
            nickname: $nickname,
            userMask: $userMask,
            targetIsOperator: $targetIsOperator,
        );
    }

    private function handler(
        ChannelAkickRepositoryInterface $akicks,
        ?ChanUserAccountPort $accounts = null,
        ?ChannelEntryNetworkQuery $network = null,
        ?AkickActions $actions = null,
        bool $founder = true,
        int $accessLevel = 0,
        ?EnforceChannelAkickHandlerInterface $enforcer = null,
    ): ManageChannelAkickHandler {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($this->channel($founder));

        $access = $this->createStub(ChannelAccessRepositoryInterface::class);
        $access->method('listByChannel')->willReturn([]);
        if ($founder) {
            $access->method('findByChannelAndNick')->willReturn(null);
        } else {
            $entry = $this->createStub(ChannelAccess::class);
            $entry->method('getLevel')->willReturn($accessLevel);
            $access->method('findByChannelAndNick')->willReturn($entry);
        }
        $levels = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levels->method('findByChannelAndKey')->willReturn(null);

        $network ??= $this->createStub(ChannelEntryNetworkQuery::class);
        $actions ??= $this->createStub(AkickActions::class);

        return new ManageChannelAkickHandler(
            $channels,
            $akicks,
            $accounts ?? $this->createStub(ChanUserAccountPort::class),
            $access,
            new ChanServAccessHelper($access, $levels),
            new AkickAdditionPolicy(),
            new AkickMatchPolicy(),
            new AkickProtectionPolicy(),
            $network,
            $actions,
            $this->createStub(ChanServEventPublisher::class),
            $enforcer ?? new EnforceChannelAkickHandler(
                $channels,
                $akicks,
                $network,
                $actions,
                new AkickMatchPolicy(),
                new AkickUseRecorder($akicks),
            ),
            new AkickUseRecorder($akicks),
        );
    }

    private function channel(bool $founder): RegisteredChannel
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#Test');
        $channel->method('getFounderNickId')->willReturn(1);
        $channel->method('isFounder')->willReturn($founder);
        $channel->method('isBlocked')->willReturn(false);

        return $channel;
    }
}
