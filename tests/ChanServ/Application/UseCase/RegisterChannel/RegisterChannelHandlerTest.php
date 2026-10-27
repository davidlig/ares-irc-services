<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\RegisterChannel;

use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelRegisterThrottlePort;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;
use App\ChanServ\Application\Port\Out\ChanTransactionBoundary;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegistrationModeQuery;
use App\ChanServ\Application\PublishedEvent\ChannelRegisteredEvent;
use App\ChanServ\Application\Service\ChannelRegistrationActivator;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannel;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelHandler;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelOutcome;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelResult;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelAlreadyRegisteredException;
use App\ChanServ\Domain\ValueObject\ChannelStatus;
use App\ChanServ\Domain\ValueObject\RegistrationMode;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function serialize;

#[CoversClass(RegisterChannelHandler::class)]
#[CoversClass(RegisterChannelResult::class)]
final class RegisterChannelHandlerTest extends TestCase
{
    #[Test]
    public function registrationResultsExposeEachOutcomeAndItsOptionalDetails(): void
    {
        $until = new DateTimeImmutable('2026-10-27T12:00:00+00:00');
        self::assertSame(RegisterChannelOutcome::Registered, RegisterChannelResult::registered()->outcome);
        self::assertSame(RegisterChannelOutcome::AwaitingApproval, RegisterChannelResult::awaitingApproval()->outcome);
        $restricted = RegisterChannelResult::registrationRestricted($until);
        self::assertSame(RegisterChannelOutcome::RegistrationRestricted, $restricted->outcome);
        self::assertSame($until->format(DATE_ATOM), $restricted->restrictedUntil?->format(DATE_ATOM));
        self::assertSame(RegisterChannelOutcome::NotIdentified, RegisterChannelResult::notIdentified()->outcome);
        self::assertSame(RegisterChannelOutcome::PendingDeletion, RegisterChannelResult::pendingDeletion()->outcome);
        self::assertSame(RegisterChannelOutcome::ChannelNotOnNetwork, RegisterChannelResult::channelNotOnNetwork()->outcome);
        self::assertSame(RegisterChannelOutcome::InsufficientChannelRank, RegisterChannelResult::insufficientChannelRank()->outcome);
        $throttled = RegisterChannelResult::throttled(5);
        self::assertSame(RegisterChannelOutcome::Throttled, $throttled->outcome);
        self::assertSame(5, $throttled->remainingMinutes);
        $limited = RegisterChannelResult::founderLimitExceeded(3);
        self::assertSame(RegisterChannelOutcome::FounderLimitExceeded, $limited->outcome);
        self::assertSame(3, $limited->maximumChannels);
    }

    #[Test]
    public function directRegistrationActivatesAndPublishesTheExistingRegisteredEvent(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('existsByChannelName')->with('#test')->willReturn(false);
        $channels->expects(self::once())->method('findByFounderNickId')->with(7)->willReturn([]);
        $channels->expects(self::once())->method('save')->with(self::callback(static function (RegisteredChannel $channel): bool {
            new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 42);

            return ChannelStatus::Active === $channel->getStatus() && 7 === $channel->getFounderNickId();
        }));
        $throttle = $this->createMock(ChannelRegisterThrottlePort::class);
        $throttle->expects(self::once())->method('getRemainingCooldownSeconds')->with(7, 21600)->willReturn(0);
        $throttle->expects(self::once())->method('recordRegistration')->with(7);
        $operator = $this->createStub(ChanServOperatorAccess::class);
        $operator->method('isIrcop')->willReturn(false);
        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(ChannelRegisteredEvent::class));
        $handler = new RegisterChannelHandler(
            $channels,
            new ChannelRegistrationActivator($this->createStub(ChannelLevelRepositoryInterface::class), $this->createStub(ChannelAkickRepositoryInterface::class)),
            $throttle,
            $events,
            $operator,
            $this->createStub(ChanTransactionBoundary::class),
        );

        $result = $handler->handle(new RegisterChannel('#test', 'description', 7, 'Requester', true, false, true, true, $at));

        self::assertSame(RegisterChannelOutcome::Registered, $result->outcome);
    }

    #[Test]
    public function directRegistrationRejectsMissingNetworkChannelWithoutPersistingOrConsumingThrottle(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('existsByChannelName')->willReturn(false);
        $channels->expects(self::never())->method('save');
        $throttle = $this->createMock(ChannelRegisterThrottlePort::class);
        $throttle->expects(self::never())->method('getRemainingCooldownSeconds');
        $throttle->expects(self::never())->method('recordRegistration');
        $operator = $this->createMock(ChanServOperatorAccess::class);
        $operator->expects(self::never())->method('isIrcop');

        $handler = new RegisterChannelHandler(
            $channels,
            new ChannelRegistrationActivator($this->createStub(ChannelLevelRepositoryInterface::class), $this->createStub(ChannelAkickRepositoryInterface::class)),
            $throttle,
            $this->createStub(EventBusInterface::class),
            $operator,
            $this->createStub(ChanTransactionBoundary::class),
        );

        self::assertSame(RegisterChannelOutcome::ChannelNotOnNetwork, $handler->handle(new RegisterChannel('#test', 'description', 7, 'Requester', true, false, false, true, $at))->outcome);
    }

    #[Test]
    public function approvalModePersistsBlockedRequestWithoutRegisteringOrPublishing(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('existsByChannelName')->with('#test')->willReturn(false);
        $channels->expects(self::once())->method('findByFounderNickId')->with(7)->willReturn([]);
        $channels->expects(self::once())->method('save')->with(self::callback(static function (RegisteredChannel $channel): bool {
            return $channel->isPendingRegistration()
                && ChannelStatus::Pending === $channel->getStatus()
                && 7 === $channel->getFounderNickId();
        }));
        $throttle = $this->createMock(ChannelRegisterThrottlePort::class);
        $throttle->expects(self::once())->method('getRemainingCooldownSeconds')->with(7, 21600)->willReturn(0);
        $throttle->expects(self::once())->method('recordRegistration')->with(7);
        $operator = $this->createStub(ChanServOperatorAccess::class);
        $operator->method('isIrcop')->willReturn(false);
        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::never())->method('dispatch');
        $handler = new RegisterChannelHandler(
            $channels,
            new ChannelRegistrationActivator(
                $this->createStub(ChannelLevelRepositoryInterface::class),
                $this->createStub(ChannelAkickRepositoryInterface::class),
            ),
            $throttle,
            $events,
            $operator,
            $this->createStub(ChanTransactionBoundary::class),
            registrationMode: $this->mode(RegistrationMode::Approval),
        );

        $result = $handler->handle(new RegisterChannel('#test', 'description', 7, 'Requester', true, false, true, true, $at));

        self::assertSame(RegisterChannelOutcome::AwaitingApproval, $result->outcome);
    }

    #[Test]
    public function expiredRejectedRequestIsNotDeletedWhenNewRequestFailsIdentityValidation(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $rejected = RegisteredChannel::createPendingRegistration($at->modify('-2 days'), '#test', 8, 'old');
        $rejected->rejectRegistration('Rejected', 9, 'Reviewer', $at->modify('-2 days'), $at->modify('-1 day'));
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('existsByChannelName')->with('#test')->willReturn(true);
        $channels->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($rejected);
        $channels->expects(self::never())->method('delete');
        $channels->expects(self::never())->method('save');
        $operator = $this->createStub(ChanServOperatorAccess::class);
        $handler = new RegisterChannelHandler(
            $channels,
            new ChannelRegistrationActivator(
                $this->createStub(ChannelLevelRepositoryInterface::class),
                $this->createStub(ChannelAkickRepositoryInterface::class),
            ),
            $this->createStub(ChannelRegisterThrottlePort::class),
            $this->createStub(EventBusInterface::class),
            $operator,
            $this->createStub(ChanTransactionBoundary::class),
        );

        $result = $handler->handle(new RegisterChannel('#test', 'description', null, 'Requester', false, false, true, true, $at));

        self::assertSame(RegisterChannelOutcome::NotIdentified, $result->outcome);
        self::assertTrue($rejected->isRejectedRegistration());
    }

    #[Test]
    public function registeredChannelConflictPreservesExistingRegistration(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $existing = RegisteredChannel::register($at, '#test', 8, 'Existing');
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('existsByChannelName')->willReturn(true);
        $channels->expects(self::once())->method('findByChannelName')->willReturn($existing);
        $channels->expects(self::never())->method('delete');
        $channels->expects(self::never())->method('save');
        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::never())->method('dispatch');
        $handler = new RegisterChannelHandler(
            $channels,
            new ChannelRegistrationActivator($this->createStub(ChannelLevelRepositoryInterface::class), $this->createStub(ChannelAkickRepositoryInterface::class)),
            $this->createStub(ChannelRegisterThrottlePort::class),
            $events,
            $this->createStub(ChanServOperatorAccess::class),
            $this->createStub(ChanTransactionBoundary::class),
        );
        $before = serialize($existing);
        $this->expectException(ChannelAlreadyRegisteredException::class);
        try {
            $handler->handle(new RegisterChannel('#test', 'Replacement', 7, 'Requester', true, false, true, true, $at));
        } finally {
            self::assertSame($before, serialize($existing));
        }
    }

    #[Test]
    public function expiredRejectionIsReplacedAtomicallyAfterEligibilityValidation(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $existing = RegisteredChannel::createPendingRegistration($at->modify('-2 days'), '#test', 8, 'Old request');
        $existing->rejectRegistration('Reason', 9, 'Reviewer', $at->modify('-2 days'), $at->modify('-1 day'));
        $operations = [];
        $saved = null;
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('existsByChannelName')->willReturn(true);
        $channels->expects(self::once())->method('findByChannelName')->willReturn($existing);
        $channels->expects(self::once())->method('findByFounderNickId')->with(7)->willReturn([]);
        $channels->expects(self::once())->method('delete')->with($existing)->willReturnCallback(static function () use (&$operations): void { $operations[] = 'delete'; });
        $channels->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredChannel $channel) use (&$operations, &$saved): void {
            $operations[] = 'save';
            $saved = $channel;
        });
        $transaction = $this->createMock(ChanTransactionBoundary::class);
        $transaction->expects(self::once())->method('transactional')->willReturnCallback(static function (callable $operation) use (&$operations): void {
            $operations[] = 'begin';
            $operation();
            $operations[] = 'commit';
        });
        $throttle = $this->createMock(ChannelRegisterThrottlePort::class);
        $throttle->expects(self::once())->method('getRemainingCooldownSeconds')->with(7, 21600)->willReturn(0);
        $throttle->expects(self::once())->method('recordRegistration')->with(7);
        $operator = $this->createStub(ChanServOperatorAccess::class);
        $operator->method('isIrcop')->willReturn(false);
        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::never())->method('dispatch');
        $handler = new RegisterChannelHandler(
            $channels,
            new ChannelRegistrationActivator($this->createStub(ChannelLevelRepositoryInterface::class), $this->createStub(ChannelAkickRepositoryInterface::class)),
            $throttle,
            $events,
            $operator,
            $transaction,
            registrationMode: $this->mode(RegistrationMode::Approval),
        );

        $result = $handler->handle(new RegisterChannel('#test', 'New request', 7, 'Requester', true, false, true, true, $at));

        self::assertSame(RegisterChannelOutcome::AwaitingApproval, $result->outcome);
        self::assertSame(['begin', 'delete', 'save', 'commit'], $operations);
        self::assertInstanceOf(RegisteredChannel::class, $saved);
        self::assertTrue($saved->isPendingRegistration());
        self::assertSame(7, $saved->getFounderNickId());
        self::assertSame('New request', $saved->getDescription());
    }

    private function mode(RegistrationMode $mode): RegistrationModeQuery
    {
        $query = $this->createStub(RegistrationModeQuery::class);
        $query->method('current')->willReturn($mode);

        return $query;
    }
}
