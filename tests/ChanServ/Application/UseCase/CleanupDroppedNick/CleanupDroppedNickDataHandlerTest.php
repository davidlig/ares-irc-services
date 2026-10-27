<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\CleanupDroppedNick;

use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\ChanTransactionBoundary;
use App\ChanServ\Application\Port\Out\NickDropCleanupActivitySink;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
use App\ChanServ\Application\PublishedEvent\ChannelDropEvent;
use App\ChanServ\Application\UseCase\CleanupDroppedNick\CleanupDroppedNickData;
use App\ChanServ\Application\UseCase\CleanupDroppedNick\CleanupDroppedNickDataHandler;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function implode;

#[CoversClass(CleanupDroppedNickData::class)]
#[CoversClass(CleanupDroppedNickDataHandler::class)]
final class CleanupDroppedNickDataHandlerTest extends TestCase
{
    #[Test]
    public function cleansReferencesInTheHistoricalOrder(): void
    {
        $calls = [];
        $accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepository->expects(self::once())->method('deleteByNickId')->with(42)->willReturnCallback(
            static function () use (&$calls): void { $calls[] = 'access'; },
        );
        $akickRepository = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akickRepository->expects(self::once())->method('clearCreatorNickId')->with(42)->willReturnCallback(
            static function () use (&$calls): void { $calls[] = 'akick'; },
        );
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('clearSuccessorNickId')->with(42)->willReturnCallback(
            static function () use (&$calls): void { $calls[] = 'successor'; },
        );
        $channelRepository->expects(self::once())->method('findByFounderNickId')->with(42)->willReturnCallback(
            static function () use (&$calls): array {
                $calls[] = 'founder';

                return [];
            },
        );

        $this->handler($accessRepository, $akickRepository, $channelRepository)->handle(new CleanupDroppedNickData(
            42,
            new DateTimeImmutable('2026-01-02 03:04:05'),
        ));

        self::assertSame(['access', 'akick', 'successor', 'founder'], $calls);
    }

    #[Test]
    public function transfersFounderToSuccessorAndPersistsIt(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->method('getSuccessorNickId')->willReturn(88);
        $channel->method('getId')->willReturn(7);
        $channel->method('getName')->willReturn('#successor');
        $channel->expects(self::once())->method('changeFounder')->with(88);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByFounderNickId')->willReturn([$channel]);
        $channelRepository->expects(self::once())->method('save')->with($channel);
        $activitySink = $this->createMock(NickDropCleanupActivitySink::class);
        $activitySink->expects(self::once())->method('founderTransferred')->with(7, '#successor', 88);
        $activitySink->expects(self::never())->method('channelDropped');
        $eventPublisher = $this->createMock(ChanServEventPublisher::class);
        $eventPublisher->expects(self::never())->method('publish');

        $this->handler(
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelAkickRepositoryInterface::class),
            $channelRepository,
            $eventPublisher,
            $this->createStub(ChanTransactionBoundary::class),
            $activitySink,
        )->handle(new CleanupDroppedNickData(51, new DateTimeImmutable('2026-01-02 03:04:05')));
    }

    #[Test]
    public function publishesCleanupBeforeDeleteAndDefersPublicDropUntilCommit(): void
    {
        $calls = [];
        $afterCommit = null;
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channel->method('getId')->willReturn(9);
        $channel->method('getName')->willReturn('#orphan');
        $channel->method('getNameLower')->willReturn('#orphan');

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByFounderNickId')->willReturn([$channel]);
        $channelRepository->expects(self::once())->method('delete')->with($channel)->willReturnCallback(
            static function () use (&$calls): void { $calls[] = 'delete'; },
        );
        $eventPublisher = $this->createMock(ChanServEventPublisher::class);
        $eventPublisher->expects(self::exactly(2))->method('publish')->willReturnCallback(
            static function (ChannelDropCleanupEvent|ChannelDropEvent $event) use (&$calls): void {
                $calls[] = $event instanceof ChannelDropCleanupEvent ? 'cleanup' : 'drop';
                self::assertSame(9, $event->channelId);
                self::assertSame('#orphan', $event->channelName);
                self::assertSame('founder_dropped', $event->reason);
            },
        );
        $transactionBoundary = $this->createMock(ChanTransactionBoundary::class);
        $transactionBoundary->expects(self::once())->method('afterCommit')->willReturnCallback(
            static function (callable $operation) use (&$afterCommit, &$calls): void {
                $calls[] = 'after_commit_registered';
                $afterCommit = $operation;
            },
        );
        $activitySink = $this->createMock(NickDropCleanupActivitySink::class);
        $activitySink->expects(self::once())->method('channelDropped')->with(9, '#orphan')->willReturnCallback(
            static function () use (&$calls): void { $calls[] = 'activity'; },
        );

        $this->handler(
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelAkickRepositoryInterface::class),
            $channelRepository,
            $eventPublisher,
            $transactionBoundary,
            $activitySink,
        )->handle(new CleanupDroppedNickData(52, new DateTimeImmutable('2026-01-02 03:04:05')));

        self::assertSame('cleanup,delete,after_commit_registered,activity', implode(',', $calls));
        self::assertIsCallable($afterCommit);
        $afterCommit();
        self::assertSame('cleanup,delete,after_commit_registered,activity,drop', implode(',', $calls));
    }

    #[Test]
    public function preservesRejectedNameRestrictionsWhenRequesterNicknameIsDropped(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $finite = RegisteredChannel::createPendingRegistration($at, '#finite', 7, 'description');
        $finite->rejectRegistration('Reason', 9, 'Reviewer', $at, $at->modify('+24 hours'));
        $indefinite = RegisteredChannel::createPendingRegistration($at, '#indefinite', 7, 'description');
        $indefinite->rejectRegistration('Reason', 9, 'Reviewer', $at, null);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByFounderNickId')->with(7)->willReturn([$finite, $indefinite]);
        $channels->expects(self::exactly(2))->method('save')->willReturnCallback(static function (RegisteredChannel $channel): void {
            self::assertSame(0, $channel->getFounderNickId());
        });
        $channels->expects(self::never())->method('delete');
        $events = $this->createMock(ChanServEventPublisher::class);
        $events->expects(self::never())->method('publish');
        $transaction = $this->createMock(ChanTransactionBoundary::class);
        $transaction->expects(self::never())->method('afterCommit');
        $activity = $this->createMock(NickDropCleanupActivitySink::class);
        $activity->expects(self::never())->method('channelDropped');
        $activity->expects(self::never())->method('founderTransferred');

        $this->handler(
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelAkickRepositoryInterface::class),
            $channels,
            $events,
            $transaction,
            $activity,
        )->handle(new CleanupDroppedNickData(7, $at));

        self::assertSame(0, $finite->getFounderNickId());
        self::assertSame(0, $indefinite->getFounderNickId());
        self::assertSame($at->modify('+24 hours')->format(DATE_ATOM), $finite->getRegistrationRestrictedUntil()?->format(DATE_ATOM));
        self::assertNull($indefinite->getRegistrationRestrictedUntil());
        self::assertSame('Reason', $finite->getRegistrationRejectionReason());
        self::assertSame('Reviewer', $indefinite->getRegistrationRejectedByNickname());
    }

    #[Test]
    public function removesPendingRequestWithoutPublishingRegisteredChannelDropEvents(): void
    {
        $pending = RegisteredChannel::createPendingRegistration(new DateTimeImmutable(), '#pending', 7, 'description');
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByFounderNickId')->willReturn([$pending]);
        $channels->expects(self::once())->method('delete')->with($pending);
        $channels->expects(self::never())->method('save');
        $events = $this->createMock(ChanServEventPublisher::class);
        $events->expects(self::never())->method('publish');

        $this->handler($this->createStub(ChannelAccessRepositoryInterface::class), $this->createStub(ChannelAkickRepositoryInterface::class), $channels, $events)->handle(new CleanupDroppedNickData(7, new DateTimeImmutable()));
    }

    private function handler(
        ChannelAccessRepositoryInterface $accessRepository,
        ChannelAkickRepositoryInterface $akickRepository,
        RegisteredChannelRepositoryInterface $channelRepository,
        ?ChanServEventPublisher $eventPublisher = null,
        ?ChanTransactionBoundary $transactionBoundary = null,
        ?NickDropCleanupActivitySink $activitySink = null,
    ): CleanupDroppedNickDataHandler {
        return new CleanupDroppedNickDataHandler(
            $accessRepository,
            $akickRepository,
            $channelRepository,
            $eventPublisher ?? $this->createStub(ChanServEventPublisher::class),
            $transactionBoundary ?? $this->createStub(ChanTransactionBoundary::class),
            $activitySink ?? $this->createStub(NickDropCleanupActivitySink::class),
        );
    }
}
