<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\ReviewRegistration;

use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelRegistrationAuditRepository;
use App\ChanServ\Application\Port\Out\ChanTransactionBoundary;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelRegisteredEvent;
use App\ChanServ\Application\PublishedEvent\ChannelRegistrationDecisionEvent;
use App\ChanServ\Application\Service\ChannelHistoryService;
use App\ChanServ\Application\Service\ChannelRegistrationActivator;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistration;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationHandler;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationOutcome;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationResult;
use App\ChanServ\Domain\Entity\ChannelHistory;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\ChannelRegistrationAudit;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelStatus;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(ReviewRegistrationHandler::class)]
#[CoversClass(ReviewRegistration::class)]
#[CoversClass(ReviewRegistrationResult::class)]
final class ReviewRegistrationHandlerTest extends TestCase
{
    #[Test]
    public function reviewInputRetainsAllCurrentDecisionFacts(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $request = new ReviewRegistration('#test', true, 9, 'Reviewer', $at, 'Needs a topic', true);

        self::assertSame('#test', $request->channelName);
        self::assertTrue($request->channelExistsOnNetwork);
        self::assertSame(9, $request->operatorAccountId);
        self::assertSame('Reviewer', $request->operatorNickname);
        self::assertSame($at->format(DATE_ATOM), $request->occurredAt->format(DATE_ATOM));
        self::assertSame('Needs a topic', $request->rejectionReason);
        self::assertTrue($request->requesterHasRequiredChannelRank);
    }

    #[Test]
    public function acceptanceRejectsChannelMissingFromNetworkBeforeIdentityLookup(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $pending = $this->pendingChannel($at);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByChannelName')->willReturn($pending);
        $channels->expects(self::never())->method('save');
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::never())->method('findAccountById');
        $handler = new ReviewRegistrationHandler(
            $channels,
            new ChannelRegistrationActivator($this->createStub(ChannelLevelRepositoryInterface::class), $this->createStub(ChannelAkickRepositoryInterface::class)),
            $this->createStub(EventBusInterface::class),
            new ChannelHistoryService($this->createStub(ChannelHistoryRepositoryInterface::class)),
            $accounts,
            $this->createStub(ChannelRegistrationAuditRepository::class),
            $this->createStub(ChanTransactionBoundary::class),
        );

        self::assertSame(ReviewRegistrationOutcome::ChannelNotOnNetwork, $handler->handle(new ReviewRegistration('#test', false, 9, 'Operator', $at))->outcome);
        self::assertTrue($pending->isPendingRegistration());
    }

    #[Test]
    public function zeroCooldownCreatesIndefiniteRejectedStateAndNegativeCooldownIsInvalid(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $pending = $this->pendingChannel($at);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByChannelName')->willReturn($pending);
        $channels->expects(self::once())->method('save')->with($pending);
        $audit = $this->createMock(ChannelRegistrationAuditRepository::class);
        $audit->expects(self::once())->method('save')->with(self::callback(static fn (ChannelRegistrationAudit $record): bool => !$record->wasAccepted() && null === $record->getRestrictedUntil() && 'Reason' === $record->getRejectionReason()));
        $transaction = $this->createMock(ChanTransactionBoundary::class);
        $transaction->expects(self::once())->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(ChannelRegistrationDecisionEvent::class));
        $handler = new ReviewRegistrationHandler(
            $channels,
            new ChannelRegistrationActivator($this->createStub(ChannelLevelRepositoryInterface::class), $this->createStub(ChannelAkickRepositoryInterface::class)),
            $events,
            new ChannelHistoryService($this->createStub(ChannelHistoryRepositoryInterface::class)),
            $this->createStub(ChanUserAccountPort::class),
            $audit,
            $transaction,
            rejectionCooldownHours: 0,
        );

        self::assertSame(ReviewRegistrationOutcome::Rejected, $handler->handle(new ReviewRegistration('#test', true, 9, 'Operator', $at, 'Reason'))->outcome);
        self::assertTrue($pending->isRejectedRegistration());
        self::assertNull($pending->getRegistrationRestrictedUntil());

        $this->expectException(InvalidArgumentException::class);
        new ReviewRegistrationHandler(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            new ChannelRegistrationActivator($this->createStub(ChannelLevelRepositoryInterface::class), $this->createStub(ChannelAkickRepositoryInterface::class)),
            $this->createStub(EventBusInterface::class),
            new ChannelHistoryService($this->createStub(ChannelHistoryRepositoryInterface::class)),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(ChannelRegistrationAuditRepository::class),
            $this->createStub(ChanTransactionBoundary::class),
            rejectionCooldownHours: -1,
        );
    }

    #[Test]
    public function acceptanceIsRefusedWhenRequesterAccountIsNoLongerActive(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $channel = $this->pendingChannel($at);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByChannelName')->willReturn($channel);
        $channels->expects(self::never())->method('save');
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::once())->method('findAccountById')->with(7)->willReturn(new ChanAccountView(7, 'Requester', 'en', suspended: true));
        $handler = new ReviewRegistrationHandler(
            $channels,
            new ChannelRegistrationActivator(
                $this->createStub(ChannelLevelRepositoryInterface::class),
                $this->createStub(ChannelAkickRepositoryInterface::class),
            ),
            $this->createStub(EventBusInterface::class),
            new ChannelHistoryService($this->createStub(ChannelHistoryRepositoryInterface::class)),
            $accounts,
            $this->createStub(ChannelRegistrationAuditRepository::class),
            $this->createStub(ChanTransactionBoundary::class),
        );

        $result = $handler->handle(new ReviewRegistration('#test', true, 9, 'ApprovingOper', $at, requesterHasRequiredChannelRank: true));

        self::assertSame(ReviewRegistrationOutcome::RequesterInactive, $result->outcome);
        self::assertSame(ChannelStatus::Pending, $channel->getStatus());
    }

    #[Test]
    public function acceptActivatesPendingChannelPublishesEventsAndWritesApprovingNicknameToHistory(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $channel = $this->pendingChannel($at);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);
        $channels->expects(self::once())->method('findByFounderNickId')->with(7)->willReturn([]);
        $channels->expects(self::once())->method('save')->with($channel);
        $levels = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levels->expects(self::once())->method('removeAllForChannel')->with(41);
        $levels->expects(self::exactly(count(ChannelLevel::DEFAULTS)))->method('save');
        $akicks = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akicks->expects(self::once())->method('deleteByChannelId')->with(41);
        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::exactly(2))->method('dispatch')->with(self::callback(static fn (object $event): bool => $event instanceof ChannelRegistrationDecisionEvent || $event instanceof ChannelRegisteredEvent));
        $history = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $history->expects(self::once())->method('save')->with(self::callback(static function (ChannelHistory $entry): bool {
            return 'CREG_ACCEPT' === $entry->getAction() && 'ApprovingOper' === $entry->getPerformedBy();
        }));

        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::once())->method('findAccountById')->with(7)->willReturn(new ChanAccountView(7, 'Requester', 'en'));
        $audit = $this->createMock(ChannelRegistrationAuditRepository::class);
        $audit->expects(self::once())->method('save');
        $transaction = $this->createMock(ChanTransactionBoundary::class);
        $transaction->expects(self::once())->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $handler = new ReviewRegistrationHandler($channels, new ChannelRegistrationActivator($levels, $akicks), $events, new ChannelHistoryService($history), $accounts, $audit, $transaction);
        $result = $handler->handle(new ReviewRegistration('#test', true, 9, 'ApprovingOper', $at, requesterHasRequiredChannelRank: true));

        self::assertSame(ReviewRegistrationOutcome::Accepted, $result->outcome);
        self::assertSame(ChannelStatus::Active, $channel->getStatus());
    }

    #[Test]
    public function rejectStoresReasonOperatorAndFixedCooldownAndDoesNotActivate(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $channel = $this->pendingChannel($at);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);
        $channels->expects(self::never())->method('findByFounderNickId');
        $channels->expects(self::once())->method('save')->with($channel);
        $levels = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levels->expects(self::never())->method('save');
        $akicks = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akicks->expects(self::never())->method('deleteByChannelId');
        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(ChannelRegistrationDecisionEvent::class));
        $audit = $this->createMock(ChannelRegistrationAuditRepository::class);
        $audit->expects(self::once())->method('save');
        $transaction = $this->createMock(ChanTransactionBoundary::class);
        $transaction->expects(self::once())->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $history = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $history->expects(self::never())->method('save');

        $handler = new ReviewRegistrationHandler(
            $channels,
            new ChannelRegistrationActivator($levels, $akicks),
            $events,
            new ChannelHistoryService($history),
            $this->createStub(ChanUserAccountPort::class),
            $audit,
            $transaction,
            rejectionCooldownHours: 24,
        );
        $result = $handler->handle(new ReviewRegistration('#test', false, 9, 'ApprovingOper', $at, 'Needs a public topic'));

        self::assertSame(ReviewRegistrationOutcome::Rejected, $result->outcome);
        self::assertSame(ChannelStatus::Rejected, $channel->getStatus());
        self::assertSame('Needs a public topic', $channel->getRegistrationRejectionReason());
        self::assertSame('ApprovingOper', $channel->getRegistrationRejectedByNickname());
        self::assertSame($at->modify('+24 hours')->format(DATE_ATOM), $channel->getRegistrationRestrictedUntil()?->format(DATE_ATOM));
    }

    #[Test]
    public function acceptanceRejectsRequesterWithoutCurrentChannelEligibility(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $channel = $this->pendingChannel($at);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByChannelName')->willReturn($channel);
        $channels->expects(self::never())->method('findByFounderNickId');
        $channels->expects(self::never())->method('save');
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::once())->method('findAccountById')->with(7)->willReturn(new ChanAccountView(7, 'Requester', 'en'));
        $transaction = $this->createMock(ChanTransactionBoundary::class);
        $transaction->expects(self::never())->method('transactional');
        $handler = new ReviewRegistrationHandler(
            $channels,
            new ChannelRegistrationActivator($this->createStub(ChannelLevelRepositoryInterface::class), $this->createStub(ChannelAkickRepositoryInterface::class)),
            $this->neverDispatchEvents(),
            new ChannelHistoryService($this->createStub(ChannelHistoryRepositoryInterface::class)),
            $accounts,
            $this->createStub(ChannelRegistrationAuditRepository::class),
            $transaction,
        );

        $result = $handler->handle(new ReviewRegistration('#test', true, 9, 'ApprovingOper', $at));

        self::assertSame(ReviewRegistrationOutcome::RequesterIneligible, $result->outcome);
        self::assertSame(ChannelStatus::Pending, $channel->getStatus());
    }

    #[Test]
    public function acceptanceEnforcesActiveChannelLimitAndExistingChannelConflicts(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $pending = $this->pendingChannel($at);
        $competitors = [];
        foreach (['#one', '#two', '#three'] as $index => $name) {
            $competitor = RegisteredChannel::register($at, $name, 7, 'existing');
            new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($competitor, 51 + $index);
            $competitors[] = $competitor;
        }
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByChannelName')->willReturn($pending);
        $channels->expects(self::once())->method('findByFounderNickId')->with(7)->willReturn($competitors);
        $channels->expects(self::never())->method('save');
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountById')->willReturn(new ChanAccountView(7, 'Requester', 'en'));
        $events = $this->neverDispatchEvents();
        $transaction = $this->createMock(ChanTransactionBoundary::class);
        $transaction->expects(self::never())->method('transactional');
        $handler = new ReviewRegistrationHandler(
            $channels,
            new ChannelRegistrationActivator($this->createStub(ChannelLevelRepositoryInterface::class), $this->createStub(ChannelAkickRepositoryInterface::class)),
            $events,
            new ChannelHistoryService($this->createStub(ChannelHistoryRepositoryInterface::class)),
            $accounts,
            $this->createStub(ChannelRegistrationAuditRepository::class),
            $transaction,
            maxChannelsPerNick: 3,
        );

        $result = $handler->handle(new ReviewRegistration('#test', true, 9, 'ApprovingOper', $at, requesterHasRequiredChannelRank: true));

        self::assertSame(ReviewRegistrationOutcome::FounderLimitExceeded, $result->outcome);
        self::assertSame(ChannelStatus::Pending, $pending->getStatus());
    }

    #[Test]
    public function existingNonPendingChannelCannotBeReviewed(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 7, 'already active');
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByChannelName')->willReturn($channel);
        $channels->expects(self::never())->method('save');
        $handler = new ReviewRegistrationHandler(
            $channels,
            new ChannelRegistrationActivator($this->createStub(ChannelLevelRepositoryInterface::class), $this->createStub(ChannelAkickRepositoryInterface::class)),
            $this->createStub(EventBusInterface::class),
            new ChannelHistoryService($this->createStub(ChannelHistoryRepositoryInterface::class)),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(ChannelRegistrationAuditRepository::class),
            $this->createStub(ChanTransactionBoundary::class),
        );

        self::assertSame(ReviewRegistrationOutcome::NotPending, $handler->handle(new ReviewRegistration('#test', true, 9, 'Operator', new DateTimeImmutable()))->outcome);
    }

    #[Test]
    public function acceptanceCountsSuspendedOwnedChannelsLikeOrdinaryRegistration(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $pending = $this->pendingChannel($at);
        $owned = [];
        foreach (['#one', '#two', '#three'] as $index => $name) {
            $channel = RegisteredChannel::register($at, $name, 7, 'owned');
            new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 70 + $index);
            $channel->suspend('policy');
            $owned[] = $channel;
        }
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByChannelName')->willReturn($pending);
        $channels->expects(self::once())->method('findByFounderNickId')->with(7)->willReturn($owned);
        $channels->expects(self::never())->method('save');
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountById')->willReturn(new ChanAccountView(7, 'Requester', 'en'));
        $transaction = $this->createMock(ChanTransactionBoundary::class);
        $transaction->expects(self::never())->method('transactional');
        $transaction->expects(self::never())->method('afterCommit');
        $handler = new ReviewRegistrationHandler(
            $channels,
            new ChannelRegistrationActivator($this->createStub(ChannelLevelRepositoryInterface::class), $this->createStub(ChannelAkickRepositoryInterface::class)),
            $this->neverDispatchEvents(),
            new ChannelHistoryService($this->createStub(ChannelHistoryRepositoryInterface::class)),
            $accounts,
            $this->createStub(ChannelRegistrationAuditRepository::class),
            $transaction,
            maxChannelsPerNick: 3,
        );

        $result = $handler->handle(new ReviewRegistration('#test', true, 9, 'ApprovingOper', $at, requesterHasRequiredChannelRank: true));

        self::assertSame(ReviewRegistrationOutcome::FounderLimitExceeded, $result->outcome);
        self::assertTrue($pending->isPendingRegistration());
    }

    private function pendingChannel(DateTimeImmutable $at): RegisteredChannel
    {
        $channel = RegisteredChannel::createPendingRegistration($at, '#test', 7, 'Description');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 41);

        return $channel;
    }

    private function neverDispatchEvents(): EventBusInterface
    {
        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::never())->method('dispatch');

        return $events;
    }
}
