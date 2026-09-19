<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\ManageLifecycle;

use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelSuspendedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelUnsuspendedEvent;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Application\Service\ChannelForbiddenService;
use App\ChanServ\Application\Service\ChannelSuspensionService;
use App\ChanServ\Application\UseCase\ManageLifecycle\ChannelLifecycleAction;
use App\ChanServ\Application\UseCase\ManageLifecycle\ChannelLifecycleOutcome;
use App\ChanServ\Application\UseCase\ManageLifecycle\ChannelLifecycleResult;
use App\ChanServ\Application\UseCase\ManageLifecycle\ManageChannelLifecycle;
use App\ChanServ\Application\UseCase\ManageLifecycle\ManageChannelLifecycleHandler;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(ManageChannelLifecycleHandler::class)]
#[CoversClass(ManageChannelLifecycle::class)]
#[CoversClass(ChannelLifecycleResult::class)]
final class ManageChannelLifecycleHandlerTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-09T10:00:00+00:00');
    }

    #[Test]
    public function dropRejectsUnknownChannel(): void
    {
        $channels = $this->channelsReturning(null);

        $result = $this->handler($channels)->handle($this->command(ChannelLifecycleAction::Drop));

        self::assertSame(ChannelLifecycleOutcome::NotRegistered, $result->outcome);
    }

    #[Test]
    public function dropRejectsChannelAlreadyPendingDeletionWithoutForce(): void
    {
        $channel = $this->channel();
        $channel->markPendingDeletion($this->now);

        $result = $this->handler($this->channelsReturning($channel))->handle($this->command(ChannelLifecycleAction::Drop));

        self::assertSame(ChannelLifecycleOutcome::PendingDeletion, $result->outcome);
    }

    #[Test]
    public function dropStartsRecoverableDeletion(): void
    {
        $channel = $this->channel();
        $channels = $this->channelsReturning($channel);
        $drops = $this->createMock(ChanDropService::class);
        $drops->expects(self::once())->method('softDropChannel')->with($channel, $this->now, 'Oper');

        $result = $this->handler($channels, drops: $drops)->handle($this->command(ChannelLifecycleAction::Drop));

        self::assertSame(ChannelLifecycleOutcome::Dropped, $result->outcome);
    }

    #[Test]
    public function forcedDropPermanentlyDeletesChannel(): void
    {
        $channel = $this->channel();
        $channels = $this->channelsReturning($channel);
        $drops = $this->createMock(ChanDropService::class);
        $drops->expects(self::once())->method('hardDropChannel')->with($channel, $this->now, 'manual-force', 'Oper');

        $result = $this->handler($channels, drops: $drops)->handle($this->command(ChannelLifecycleAction::Drop, force: true));

        self::assertSame(ChannelLifecycleOutcome::ForceDropped, $result->outcome);
    }

    #[Test]
    public function forbidCreatesNewForbiddenRegistration(): void
    {
        $channels = $this->channelsReturning(null);
        $forbidden = $this->createMock(ChannelForbiddenService::class);
        $forbidden->expects(self::once())->method('forbid')->with('#test', 'abuse', 'Oper', $this->now);

        $result = $this->handler($channels, forbidden: $forbidden)->handle(
            $this->command(ChannelLifecycleAction::Forbid, reason: 'abuse'),
        );

        self::assertSame(ChannelLifecycleOutcome::Forbidden, $result->outcome);
    }

    #[Test]
    public function forbidUpdatesExistingForbiddenRegistration(): void
    {
        $channel = RegisteredChannel::createForbidden($this->now, '#test', 'old reason');
        $channels = $this->channelsReturning($channel);
        $forbidden = $this->createMock(ChannelForbiddenService::class);
        $forbidden->expects(self::once())->method('forbid')->with('#test', 'new reason', 'Oper', $this->now);

        $result = $this->handler($channels, forbidden: $forbidden)->handle(
            $this->command(ChannelLifecycleAction::Forbid, reason: 'new reason'),
        );

        self::assertSame(ChannelLifecycleOutcome::ForbiddenUpdated, $result->outcome);
    }

    #[Test]
    public function forbidRequiresReason(): void
    {
        $forbidden = $this->createMock(ChannelForbiddenService::class);
        $forbidden->expects(self::never())->method('forbid');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A forbid reason is required.');

        $this->handler($this->channelsReturning(null), forbidden: $forbidden)
            ->handle($this->command(ChannelLifecycleAction::Forbid));
    }

    /**
     * @return iterable<string, array{bool, ChannelLifecycleOutcome}>
     */
    public static function unforbidOutcomes(): iterable
    {
        yield 'removed' => [true, ChannelLifecycleOutcome::Unforbidden];
        yield 'not forbidden' => [false, ChannelLifecycleOutcome::NotForbidden];
    }

    #[Test]
    #[DataProvider('unforbidOutcomes')]
    public function unforbidReturnsSemanticOutcome(bool $removed, ChannelLifecycleOutcome $expected): void
    {
        $forbidden = $this->createStub(ChannelForbiddenService::class);
        $forbidden->method('unforbid')->willReturn($removed);

        $result = $this->handler($this->createStub(RegisteredChannelRepositoryInterface::class), forbidden: $forbidden)
            ->handle($this->command(ChannelLifecycleAction::Unforbid));

        self::assertSame($expected, $result->outcome);
    }

    #[Test]
    public function restoreRejectsUnknownChannel(): void
    {
        $result = $this->handler($this->channelsReturning(null))->handle($this->command(ChannelLifecycleAction::Restore));

        self::assertSame(ChannelLifecycleOutcome::NotRegistered, $result->outcome);
    }

    #[Test]
    public function restoreRejectsActiveChannel(): void
    {
        $result = $this->handler($this->channelsReturning($this->channel()))
            ->handle($this->command(ChannelLifecycleAction::Restore));

        self::assertSame(ChannelLifecycleOutcome::NotPendingDeletion, $result->outcome);
    }

    #[Test]
    public function restoreReactivatesPendingDeletionChannel(): void
    {
        $channel = $this->channel();
        $channel->markPendingDeletion($this->now);
        $channels = $this->channelsReturning($channel);
        $drops = $this->createMock(ChanDropService::class);
        $drops->expects(self::once())->method('restoreChannel')->with($channel, $this->now, 'Oper');

        $result = $this->handler($channels, drops: $drops)->handle($this->command(ChannelLifecycleAction::Restore));

        self::assertSame(ChannelLifecycleOutcome::Restored, $result->outcome);
    }

    #[Test]
    public function noExpireRejectsUnknownChannel(): void
    {
        $result = $this->handler($this->channelsReturning(null))
            ->handle($this->command(ChannelLifecycleAction::EnableNoExpire));

        self::assertSame(ChannelLifecycleOutcome::NotRegistered, $result->outcome);
    }

    #[Test]
    public function noExpireRejectsForbiddenChannel(): void
    {
        $channel = RegisteredChannel::createForbidden($this->now, '#test', 'abuse');

        $result = $this->handler($this->channelsReturning($channel))
            ->handle($this->command(ChannelLifecycleAction::EnableNoExpire));

        self::assertSame(ChannelLifecycleOutcome::ChannelForbidden, $result->outcome);
    }

    #[Test]
    public function noExpireRejectsSuspendedChannel(): void
    {
        $channel = $this->channel();
        $channel->suspend('abuse');

        $result = $this->handler($this->channelsReturning($channel))
            ->handle($this->command(ChannelLifecycleAction::EnableNoExpire));

        self::assertSame(ChannelLifecycleOutcome::ChannelSuspended, $result->outcome);
    }

    /**
     * @return iterable<string, array{ChannelLifecycleAction, ChannelLifecycleOutcome, bool}>
     */
    public static function noExpireChanges(): iterable
    {
        yield 'enable' => [ChannelLifecycleAction::EnableNoExpire, ChannelLifecycleOutcome::NoExpireEnabled, true];
        yield 'disable' => [ChannelLifecycleAction::DisableNoExpire, ChannelLifecycleOutcome::NoExpireDisabled, false];
    }

    #[Test]
    #[DataProvider('noExpireChanges')]
    public function noExpireChangeIsPersisted(
        ChannelLifecycleAction $action,
        ChannelLifecycleOutcome $expected,
        bool $enabled,
    ): void {
        $channel = $this->channel();
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);
        $channels->expects(self::once())->method('save')->with($channel);

        $result = $this->handler($channels)->handle($this->command($action));

        self::assertSame($expected, $result->outcome);
        self::assertSame($enabled, $channel->isNoExpire());
    }

    #[Test]
    public function suspendRejectsUnknownChannel(): void
    {
        $result = $this->handler($this->channelsReturning(null))
            ->handle($this->command(ChannelLifecycleAction::Suspend, reason: 'abuse', duration: '7d'));

        self::assertSame(ChannelLifecycleOutcome::NotRegistered, $result->outcome);
    }

    #[Test]
    public function suspendRejectsAlreadySuspendedChannel(): void
    {
        $channel = $this->channel();
        $channel->suspend('old reason');

        $result = $this->handler($this->channelsReturning($channel))
            ->handle($this->command(ChannelLifecycleAction::Suspend, reason: 'abuse', duration: '7d'));

        self::assertSame(ChannelLifecycleOutcome::AlreadySuspended, $result->outcome);
    }

    #[Test]
    public function suspendRejectsInvalidDuration(): void
    {
        $result = $this->handler($this->channelsReturning($this->channel()))
            ->handle($this->command(ChannelLifecycleAction::Suspend, reason: 'abuse', duration: 'invalid'));

        self::assertSame(ChannelLifecycleOutcome::InvalidDuration, $result->outcome);
    }

    #[Test]
    public function suspendRequiresReason(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A suspension reason is required.');

        $this->handler($this->channelsReturning($this->channel()))
            ->handle($this->command(ChannelLifecycleAction::Suspend, duration: '0'));
    }

    #[Test]
    public function permanentSuspensionPersistsEnforcesAndPublishes(): void
    {
        $channel = $this->channel();
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);
        $channels->expects(self::once())->method('save')->with($channel);
        $suspensions = $this->createMock(ChannelSuspensionService::class);
        $suspensions->expects(self::once())->method('enforceSuspension')->with($channel);
        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::once())->method('dispatch')->with(self::callback(
            function (ChannelSuspendedEvent $event): bool {
                self::assertSame(42, $event->channelId);
                self::assertSame('#test', $event->channelName);
                self::assertSame('abuse', $event->reason);
                self::assertSame('Oper', $event->performedBy);
                self::assertSame(7, $event->performedByNickId);
                self::assertSame('127.0.0.1', $event->performedByIp);
                self::assertSame('ident@host', $event->performedByHost);
                self::assertSame($this->now, $event->occurredAt);
                self::assertNull($event->duration);
                self::assertNull($event->expiresAt);

                return true;
            },
        ));

        $result = $this->handler($channels, suspensions: $suspensions, events: $events)->handle(
            $this->command(ChannelLifecycleAction::Suspend, reason: 'abuse', duration: '0'),
        );

        self::assertSame(ChannelLifecycleOutcome::Suspended, $result->outcome);
        self::assertNull($result->expiresAt);
    }

    #[Test]
    public function timedSuspensionReturnsAndPublishesExpiry(): void
    {
        $channel = $this->channel();
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);
        $channels->expects(self::once())->method('save')->with($channel);
        $suspensions = $this->createMock(ChannelSuspensionService::class);
        $suspensions->expects(self::once())->method('enforceSuspension')->with($channel);
        $expectedExpiry = $this->now->modify('+7 days');
        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::once())->method('dispatch')->with(self::callback(
            static function (ChannelSuspendedEvent $event) use ($expectedExpiry): bool {
                self::assertSame('7d', $event->duration);
                self::assertSame($expectedExpiry->getTimestamp(), $event->expiresAt?->getTimestamp());

                return true;
            },
        ));

        $result = $this->handler($channels, suspensions: $suspensions, events: $events)->handle(
            $this->command(ChannelLifecycleAction::Suspend, reason: 'abuse', duration: '7d'),
        );

        self::assertSame(ChannelLifecycleOutcome::Suspended, $result->outcome);
        self::assertSame($expectedExpiry->getTimestamp(), $result->expiresAt?->getTimestamp());
    }

    #[Test]
    public function unsuspendRejectsUnknownChannel(): void
    {
        $result = $this->handler($this->channelsReturning(null))->handle($this->command(ChannelLifecycleAction::Unsuspend));

        self::assertSame(ChannelLifecycleOutcome::NotRegistered, $result->outcome);
    }

    #[Test]
    public function unsuspendRejectsActiveChannel(): void
    {
        $result = $this->handler($this->channelsReturning($this->channel()))
            ->handle($this->command(ChannelLifecycleAction::Unsuspend));

        self::assertSame(ChannelLifecycleOutcome::NotSuspended, $result->outcome);
    }

    #[Test]
    public function unsuspendPersistsAndPublishesActorMetadata(): void
    {
        $channel = $this->channel();
        $channel->suspend('abuse');
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);
        $channels->expects(self::once())->method('save')->with($channel);
        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::once())->method('dispatch')->with(self::callback(
            function (ChannelUnsuspendedEvent $event): bool {
                self::assertSame(42, $event->channelId);
                self::assertSame('#test', $event->channelName);
                self::assertSame('Oper', $event->performedBy);
                self::assertSame(7, $event->performedByNickId);
                self::assertSame('127.0.0.1', $event->performedByIp);
                self::assertSame('ident@host', $event->performedByHost);
                self::assertSame($this->now, $event->occurredAt);

                return true;
            },
        ));

        $result = $this->handler($channels, events: $events)->handle($this->command(ChannelLifecycleAction::Unsuspend));

        self::assertSame(ChannelLifecycleOutcome::Unsuspended, $result->outcome);
        self::assertFalse($channel->isSuspended());
    }

    private function command(
        ChannelLifecycleAction $action,
        ?string $reason = null,
        ?string $duration = null,
        bool $force = false,
    ): ManageChannelLifecycle {
        return new ManageChannelLifecycle(
            channelName: '#test',
            action: $action,
            actorNickname: 'Oper',
            occurredAt: $this->now,
            actorAccountId: 7,
            actorIp: '127.0.0.1',
            actorHost: 'ident@host',
            reason: $reason,
            duration: $duration,
            force: $force,
        );
    }

    private function channel(): RegisteredChannel
    {
        $channel = RegisteredChannel::register($this->now, '#test', 1, 'Test channel');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 42);

        return $channel;
    }

    /** @return RegisteredChannelRepositoryInterface&Stub */
    private function channelsReturning(?RegisteredChannel $channel): RegisteredChannelRepositoryInterface
    {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        return $channels;
    }

    private function handler(
        RegisteredChannelRepositoryInterface $channels,
        ?ChanDropService $drops = null,
        ?ChannelForbiddenService $forbidden = null,
        ?ChannelSuspensionService $suspensions = null,
        ?EventBusInterface $events = null,
    ): ManageChannelLifecycleHandler {
        return new ManageChannelLifecycleHandler(
            $channels,
            $drops ?? $this->createStub(ChanDropService::class),
            $forbidden ?? $this->createStub(ChannelForbiddenService::class),
            $suspensions ?? $this->createStub(ChannelSuspensionService::class),
            $events ?? $this->createStub(EventBusInterface::class),
        );
    }
}
