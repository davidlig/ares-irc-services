<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\Service;

use App\ChanServ\Application\Port\Out\ChanAuditSink;
use App\ChanServ\Application\Port\Out\ChanNetworkActions;
use App\ChanServ\Application\Port\Out\ChanServActivitySink;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\ChanTransactionBoundary;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
use App\ChanServ\Application\PublishedEvent\ChannelDropEvent;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(ChanDropService::class)]
final class ChanDropServiceTest extends TestCase
{
    #[Test]
    public function dropChannelDispatchesEventAndDeletesChannel(): void
    {
        $channel = $this->createChannelWithId('#test', 42);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('delete')->with($channel);

        $calls = [];
        $eventPublisher = $this->createMock(ChanServEventPublisher::class);
        $eventPublisher->expects(self::exactly(2))->method('publish')->willReturnCallback(
            static function (ChannelDropCleanupEvent|ChannelDropEvent $event) use (&$calls): void {
                $calls[] = match ($event::class) {
                    ChannelDropCleanupEvent::class => 'cleanup',
                    ChannelDropEvent::class => 'post-commit',
                };

                self::assertSame(42, $event->channelId);
                self::assertSame('#test', $event->channelName);
                self::assertSame('manual', $event->reason);
            },
        );

        $channelRepository->method('delete')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'delete';
        });

        $transactionBoundary = $this->createMock(ChanTransactionBoundary::class);
        $transactionBoundary->expects(self::once())->method('transactional')->willReturnCallback(
            static function (callable $operation) use (&$calls): mixed {
                $calls[] = 'transaction-start';
                $result = $operation();
                $calls[] = 'commit';

                return $result;
            },
        );

        $debug = $this->createMock(ChanAuditSink::class);
        $debug->expects(self::once())->method('log')->with(
            'OperUser',
            'DROP',
            '#test',
            null,
            null,
            'manual',
        );

        $logger = $this->createMock(ChanServActivitySink::class);
        $logger->expects(self::once())->method('info');

        $service = new ChanDropService(
            $channelRepository,
            $eventPublisher,
            $debug,
            $logger,
            $this->createStub(ChanNetworkActions::class),
            $transactionBoundary,
        );

        $service->dropChannel($channel, 'manual', 'OperUser');

        self::assertSame(['transaction-start', 'cleanup', 'delete', 'commit', 'post-commit'], $calls);
    }

    #[Test]
    public function dropChannelWithInactivityReasonLogsToDebugWithAsteriskOperator(): void
    {
        $channel = $this->createChannelWithId('#inactive', 100);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('delete');

        $eventPublisher = $this->createMock(ChanServEventPublisher::class);
        $eventPublisher->expects(self::exactly(2))->method('publish')->with(self::callback(static fn (object $event): bool => ($event instanceof ChannelDropCleanupEvent || $event instanceof ChannelDropEvent)
            && 100 === $event->channelId
            && 'inactivity' === $event->reason));

        $debug = $this->createMock(ChanAuditSink::class);
        $debug->expects(self::once())->method('log')->with(
            '*',
            'DROP',
            '#inactive',
            null,
            null,
            'inactivity',
        );

        $logger = $this->createMock(ChanServActivitySink::class);
        $logger->expects(self::once())->method('info');

        $service = new ChanDropService(
            $channelRepository,
            $eventPublisher,
            $debug,
            $logger,
            $this->createStub(ChanNetworkActions::class),
            $this->immediateTransactionBoundary(),
        );

        $service->dropChannel($channel, 'inactivity', null);
    }

    #[Test]
    public function dropChannelWithManualReasonAndNullOperatorLogsToDebugWithAsteriskOperator(): void
    {
        $channel = $this->createChannelWithId('#testchan', 200);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('delete');

        $eventPublisher = $this->createMock(ChanServEventPublisher::class);
        $eventPublisher->expects(self::exactly(2))->method('publish');

        $debug = $this->createMock(ChanAuditSink::class);
        $debug->expects(self::once())->method('log')->with(
            '*',
            'DROP',
            '#testchan',
            null,
            null,
            'manual',
        );

        $logger = $this->createMock(ChanServActivitySink::class);
        $logger->expects(self::once())->method('info');

        $service = new ChanDropService(
            $channelRepository,
            $eventPublisher,
            $debug,
            $logger,
            $this->createStub(ChanNetworkActions::class),
            $this->immediateTransactionBoundary(),
        );

        $service->dropChannel($channel, 'manual', null);
    }

    #[Test]
    public function softDropChannelMarksPendingDeletionWithoutDispatchingDropEvent(): void
    {
        $channel = $this->createChannelWithId('#soft', 201);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('save')->with($channel);
        $channelRepository->expects(self::never())->method('delete');

        $eventPublisher = $this->createMock(ChanServEventPublisher::class);
        $eventPublisher->expects(self::never())->method('publish');

        $debug = $this->createMock(ChanAuditSink::class);
        $debug->expects(self::once())->method('log')->with('OperUser', 'DROP', '#soft', null, null, 'manual', self::anything());

        $channelActions = $this->createMock(ChanNetworkActions::class);
        $channelActions->expects(self::once())
            ->method('removeRegistrationForPendingDeletion')
            ->with('#soft', false, $channel->getCreatedAt()->getTimestamp());

        $service = new ChanDropService(
            $channelRepository,
            $eventPublisher,
            $debug,
            $this->createStub(ChanServActivitySink::class),
            $channelActions,
            $this->immediateTransactionBoundary(),
        );

        $service->softDropChannel($channel, 'OperUser');

        self::assertTrue($channel->isPendingDeletion());
    }

    #[Test]
    public function restoreChannelRestoresAndSaves(): void
    {
        $channel = $this->createChannelWithId('#restore', 202);
        $channel->markPendingDeletion();

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('save')->with($channel);

        $debug = $this->createMock(ChanAuditSink::class);
        $debug->expects(self::once())->method('log')->with('OperUser', 'RESTORE', '#restore', null, null, 'manual');

        $channelActions = $this->createMock(ChanNetworkActions::class);
        $channelActions->expects(self::once())
            ->method('restoreRegistrationAfterPendingDeletion')
            ->with('#restore', false, $channel->getCreatedAt()->getTimestamp());

        $service = new ChanDropService(
            $channelRepository,
            $this->createStub(ChanServEventPublisher::class),
            $debug,
            $this->createStub(ChanServActivitySink::class),
            $channelActions,
            $this->immediateTransactionBoundary(),
        );

        $service->restoreChannel($channel, 'OperUser');

        self::assertFalse($channel->isPendingDeletion());
    }

    #[Test]
    public function softDropChannelRemovesPModeWhenNoExpire(): void
    {
        $channel = $this->createChannelWithId('#softperm', 205);
        $channel->changeNoExpire(true);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('save')->with($channel);

        $channelActions = $this->createMock(ChanNetworkActions::class);
        $channelActions->expects(self::once())
            ->method('removeRegistrationForPendingDeletion')
            ->with('#softperm', true, $channel->getCreatedAt()->getTimestamp());

        $service = new ChanDropService(
            $channelRepository,
            $this->createStub(ChanServEventPublisher::class),
            $this->createStub(ChanAuditSink::class),
            $this->createStub(ChanServActivitySink::class),
            $channelActions,
            $this->immediateTransactionBoundary(),
        );

        $service->softDropChannel($channel);
    }

    #[Test]
    public function restoreChannelSetsPModeWhenNoExpire(): void
    {
        $channel = $this->createChannelWithId('#restoreperm', 206);
        $channel->changeNoExpire(true);
        $channel->markPendingDeletion();

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('save')->with($channel);

        $channelActions = $this->createMock(ChanNetworkActions::class);
        $channelActions->expects(self::once())
            ->method('restoreRegistrationAfterPendingDeletion')
            ->with('#restoreperm', true, $channel->getCreatedAt()->getTimestamp());

        $service = new ChanDropService(
            $channelRepository,
            $this->createStub(ChanServEventPublisher::class),
            $this->createStub(ChanAuditSink::class),
            $this->createStub(ChanServActivitySink::class),
            $channelActions,
            $this->immediateTransactionBoundary(),
        );

        $service->restoreChannel($channel);
    }

    private function createChannelWithId(string $name, int $id): RegisteredChannel
    {
        $channel = RegisteredChannel::register($name, 1, 'Test description');

        $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref->setValue($channel, $id);

        return $channel;
    }

    private function immediateTransactionBoundary(): ChanTransactionBoundary
    {
        $boundary = $this->createStub(ChanTransactionBoundary::class);
        $boundary->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return $boundary;
    }
}
