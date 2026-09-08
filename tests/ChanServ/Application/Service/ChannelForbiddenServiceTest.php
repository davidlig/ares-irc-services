<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\Service;

use App\ChanServ\Application\Port\Out\ChanNetworkActions;
use App\ChanServ\Application\Port\Out\ChanServActivitySink;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelForbiddenEvent;
use App\ChanServ\Application\PublishedEvent\ChannelUnforbiddenEvent;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Application\Service\ChannelForbiddenService;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(ChannelForbiddenService::class)]
final class ChannelForbiddenServiceTest extends TestCase
{
    /** @var RegisteredChannelRepositoryInterface&Stub */
    private RegisteredChannelRepositoryInterface $channelRepository;

    /** @var ChanDropService&Stub */
    private ChanDropService $dropService;

    /** @var ChanNetworkActions&Stub */
    private ChanNetworkActions $channelActions;

    /** @var ChanServEventPublisher&Stub */
    private ChanServEventPublisher $eventPublisher;

    /** @var ChanServActivitySink&Stub */
    private ChanServActivitySink $logger;

    protected function setUp(): void
    {
        $this->channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $this->dropService = $this->createStub(ChanDropService::class);
        $this->channelActions = $this->createStub(ChanNetworkActions::class);
        $this->eventPublisher = $this->createStub(ChanServEventPublisher::class);
        $this->logger = $this->createStub(ChanServActivitySink::class);
    }

    private function createService(
        ?RegisteredChannelRepositoryInterface $channelRepository = null,
        ?ChanDropService $dropService = null,
        ?ChanNetworkActions $channelActions = null,
        ?ChanServEventPublisher $eventPublisher = null,
        ?ChanServActivitySink $logger = null,
    ): ChannelForbiddenService {
        return new ChannelForbiddenService(
            $channelRepository ?? $this->channelRepository,
            $dropService ?? $this->dropService,
            $channelActions ?? $this->channelActions,
            $eventPublisher ?? $this->eventPublisher,
            $logger ?? $this->logger,
        );
    }

    private function createForbiddenChannelWithId(string $channelName, string $reason, int $id): RegisteredChannel
    {
        $channel = RegisteredChannel::createForbidden($channelName, $reason);

        $reflection = new ReflectionClass(RegisteredChannel::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($channel, $id);

        return $channel;
    }

    private function createRegisteredChannelWithId(string $channelName, int $founderId, string $desc, int $id): RegisteredChannel
    {
        $channel = RegisteredChannel::register($channelName, $founderId, $desc);

        $reflection = new ReflectionClass(RegisteredChannel::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($channel, $id);

        return $channel;
    }

    /**
     * @return RegisteredChannelRepositoryInterface&Stub
     */
    private function repositoryThatSavesAndSetsId(): RegisteredChannelRepositoryInterface
    {
        $repository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repository->method('save')
            ->willReturnCallback(static function (RegisteredChannel $channel): void {
                $reflection = new ReflectionClass(RegisteredChannel::class);
                $idProp = $reflection->getProperty('id');

                if (!$idProp->isInitialized($channel)) {
                    $idProp->setValue($channel, 999);
                }
            });

        return $repository;
    }

    // --- forbid() tests ---

    #[Test]
    public function forbidCreatesForbiddenChannelWhenNoneExists(): void
    {
        $channelRepository = $this->repositoryThatSavesAndSetsId();
        $channelRepository->method('findByChannelName')->willReturn(null);

        $dispatchedEvent = null;
        $eventPublisher = $this->createMock(ChanServEventPublisher::class);
        $eventPublisher->expects(self::once())
            ->method('publish')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvent): void {
                $dispatchedEvent = $event;
            });

        $result = $this->createService(
            channelRepository: $channelRepository,
            eventPublisher: $eventPublisher,
        )->forbid('#spam', 'Spam channel', 'OperNick');

        self::assertTrue($result->isForbidden());
        self::assertSame('#spam', $result->getName());
        self::assertSame('Spam channel', $result->getForbiddenReason());
        self::assertInstanceOf(ChannelForbiddenEvent::class, $dispatchedEvent);
        self::assertSame('Spam channel', $dispatchedEvent->reason);
        self::assertSame('OperNick', $dispatchedEvent->performedBy);
    }

    #[Test]
    public function forbidUpdatesReasonWhenAlreadyForbidden(): void
    {
        $existing = $this->createForbiddenChannelWithId('#abuse', 'Old reason', 10);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($existing);
        $channelRepository->expects(self::once())
            ->method('save')
            ->with($existing);

        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::never())->method('dropChannel');

        $dispatchedEvent = null;
        $eventPublisher = $this->createMock(ChanServEventPublisher::class);
        $eventPublisher->expects(self::once())
            ->method('publish')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvent): void {
                $dispatchedEvent = $event;
            });

        $result = $this->createService(
            channelRepository: $channelRepository,
            dropService: $dropService,
            eventPublisher: $eventPublisher,
        )->forbid('#abuse', 'New reason', 'OperNick');

        self::assertSame($existing, $result);
        self::assertSame('New reason', $result->getForbiddenReason());
        self::assertInstanceOf(ChannelForbiddenEvent::class, $dispatchedEvent);
        self::assertSame(10, $dispatchedEvent->channelId);
        self::assertSame('New reason', $dispatchedEvent->reason);
    }

    #[Test]
    public function forbidDropsActiveChannelBeforeCreatingForbidden(): void
    {
        $existing = $this->createRegisteredChannelWithId('#taken', 42, 'A channel', 5);

        $channelRepository = $this->repositoryThatSavesAndSetsId();
        $channelRepository->method('findByChannelName')->willReturn($existing);

        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::once())
            ->method('dropChannel')
            ->with($existing, 'forbid', 'OperNick');

        $channelActions = $this->createMock(ChanNetworkActions::class);
        $channelActions->expects(self::once())
            ->method('isChannelOnNetwork')
            ->with('#taken')
            ->willReturn(true);
        $channelActions->expects(self::once())
            ->method('getChannelTimestamp')
            ->with('#taken')
            ->willReturn(12345);
        $channelActions->expects(self::once())
            ->method('joinChannelAsService')
            ->with('#taken', 12345);
        $channelActions->expects(self::once())
            ->method('getChannelMemberUids')
            ->with('#taken')
            ->willReturn([]);
        $channelActions->expects(self::once())
            ->method('enforceForbiddenModes')
            ->with('#taken', 12345);

        $eventPublisher = $this->createMock(ChanServEventPublisher::class);
        $eventPublisher->expects(self::once())->method('publish');

        $this->createService(
            channelRepository: $channelRepository,
            dropService: $dropService,
            channelActions: $channelActions,
            eventPublisher: $eventPublisher,
        )->forbid('#taken', 'Forbidden', 'OperNick');
    }

    #[Test]
    public function forbidDispatchesChannelForbiddenEvent(): void
    {
        $channelRepository = $this->repositoryThatSavesAndSetsId();
        $channelRepository->method('findByChannelName')->willReturn(null);

        $dispatchedEvent = null;
        $eventPublisher = $this->createMock(ChanServEventPublisher::class);
        $eventPublisher->expects(self::once())
            ->method('publish')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvent): void {
                $dispatchedEvent = $event;
            });

        $result = $this->createService(
            channelRepository: $channelRepository,
            eventPublisher: $eventPublisher,
        )->forbid('#testchan', 'Violation', 'AdminNick');

        self::assertInstanceOf(ChannelForbiddenEvent::class, $dispatchedEvent);
        self::assertSame($result->getId(), $dispatchedEvent->channelId);
        self::assertSame($result->getName(), $dispatchedEvent->channelName);
        self::assertSame($result->getNameLower(), $dispatchedEvent->channelNameLower);
        self::assertSame('Violation', $dispatchedEvent->reason);
        self::assertSame('AdminNick', $dispatchedEvent->performedBy);
    }

    #[Test]
    public function forbidSavesChannelToRepository(): void
    {
        $channelRepository = $this->repositoryThatSavesAndSetsId();
        $channelRepository->method('findByChannelName')->willReturn(null);

        $result = $this->createService(channelRepository: $channelRepository)->forbid(
            '#forbidme',
            'Bad channel',
            'OperNick',
        );

        self::assertTrue($result->isForbidden());
        self::assertSame('#forbidme', $result->getName());
        self::assertSame('Bad channel', $result->getForbiddenReason());
    }

    #[Test]
    public function forbidCallsDropServiceWhenChannelWasRegistered(): void
    {
        $existing = $this->createRegisteredChannelWithId('#registered', 99, 'Desc', 7);

        $channelRepository = $this->repositoryThatSavesAndSetsId();
        $channelRepository->method('findByChannelName')->willReturn($existing);

        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::once())
            ->method('dropChannel')
            ->with($existing, 'forbid', 'OperNick');

        $this->createService(
            channelRepository: $channelRepository,
            dropService: $dropService,
        )->forbid('#registered', 'Now forbidden', 'OperNick');
    }

    #[Test]
    public function forbidEnforcesForbiddenChannelOnNetwork(): void
    {
        $channelRepository = $this->repositoryThatSavesAndSetsId();
        $channelRepository->method('findByChannelName')->willReturn(null);

        $channelActions = $this->createMock(ChanNetworkActions::class);
        $channelActions->expects(self::once())
            ->method('isChannelOnNetwork')
            ->with('#enforce')
            ->willReturn(true);
        $channelActions->expects(self::once())
            ->method('getChannelTimestamp')
            ->with('#enforce')
            ->willReturn(12345);
        $channelActions->expects(self::once())
            ->method('joinChannelAsService')
            ->with('#enforce', 12345);
        $channelActions->expects(self::once())
            ->method('getChannelMemberUids')
            ->with('#enforce')
            ->willReturn([]);
        $channelActions->expects(self::once())
            ->method('enforceForbiddenModes')
            ->with('#enforce', 12345);

        $this->createService(
            channelRepository: $channelRepository,
            channelActions: $channelActions,
        )->forbid('#enforce', 'Forbidden', 'Oper');
    }

    #[Test]
    public function forbidDoesNotDropWhenChannelAlreadyForbidden(): void
    {
        $existing = $this->createForbiddenChannelWithId('#forbid', 'Old', 5);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($existing);

        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::never())->method('dropChannel');

        $this->createService(
            channelRepository: $channelRepository,
            dropService: $dropService,
        )->forbid('#forbid', 'Updated reason', 'Oper');
    }

    #[Test]
    public function forbidDoesNotCallEnforceWhenChannelAlreadyForbidden(): void
    {
        $existing = $this->createForbiddenChannelWithId('#already', 'Reason', 7);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($existing);

        $channelActions = $this->createMock(ChanNetworkActions::class);
        $channelActions->expects(self::never())->method('joinChannelAsService');
        $channelActions->expects(self::never())->method('kickFromChannel');
        $channelActions->expects(self::never())->method('enforceForbiddenModes');

        $this->createService(
            channelRepository: $channelRepository,
            channelActions: $channelActions,
        )->forbid('#already', 'Updated', 'Oper');
    }

    #[Test]
    public function forbidLogsInfoWhenCreatingNewForbidden(): void
    {
        $channelRepository = $this->repositoryThatSavesAndSetsId();
        $channelRepository->method('findByChannelName')->willReturn(null);

        $logger = $this->createMock(ChanServActivitySink::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('has been forbidden'));

        $this->createService(
            channelRepository: $channelRepository,
            logger: $logger,
        )->forbid('#newforbid', 'Reason', 'Oper');
    }

    #[Test]
    public function forbidLogsInfoWhenUpdatingExistingForbidden(): void
    {
        $existing = $this->createForbiddenChannelWithId('#existing', 'Old', 3);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($existing);

        $logger = $this->createMock(ChanServActivitySink::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('Reason updated'));

        $this->createService(
            channelRepository: $channelRepository,
            logger: $logger,
        )->forbid('#existing', 'New reason', 'Oper');
    }

    #[Test]
    public function forbidLogsInfoWhenDroppingExistingChannel(): void
    {
        $existing = $this->createRegisteredChannelWithId('#regchan', 1, 'Desc', 15);

        $channelRepository = $this->repositoryThatSavesAndSetsId();
        $channelRepository->method('findByChannelName')->willReturn($existing);

        $logMessages = [];
        $logger = $this->createMock(ChanServActivitySink::class);
        $logger->expects(self::exactly(2))
            ->method('info')
            ->willReturnCallback(static function (string $message) use (&$logMessages): void {
                $logMessages[] = $message;
            });

        $this->createService(
            channelRepository: $channelRepository,
            logger: $logger,
        )->forbid('#regchan', 'Forbidden now', 'Oper');

        self::assertCount(2, $logMessages);
        self::assertStringContainsString('Dropped existing', $logMessages[0]);
        self::assertStringContainsString('has been forbidden', $logMessages[1]);
    }

    #[Test]
    public function forbidForbidsSuspendedChannel(): void
    {
        $existing = RegisteredChannel::register('#suspended', 1, 'Desc');
        $existing->suspend('Abuse');

        $channelRepository = $this->repositoryThatSavesAndSetsId();
        $channelRepository->method('findByChannelName')->willReturn($existing);

        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::once())
            ->method('dropChannel')
            ->with($existing, 'forbid', 'Oper');

        $result = $this->createService(
            channelRepository: $channelRepository,
            dropService: $dropService,
        )->forbid('#suspended', 'Now forbidden', 'Oper');

        self::assertTrue($result->isForbidden());
    }

    // --- unforbid() tests ---

    #[Test]
    public function unforbidReturnsTrueAndDeletesForbiddenChannel(): void
    {
        $channel = $this->createForbiddenChannelWithId('#badchan', 'Spam', 20);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);
        $channelRepository->expects(self::once())
            ->method('delete')
            ->with($channel);

        $eventPublisher = $this->createMock(ChanServEventPublisher::class);
        $eventPublisher->expects(self::once())
            ->method('publish')
            ->willReturnCallback(static function (object $event): void {
                self::assertInstanceOf(ChannelUnforbiddenEvent::class, $event);
                self::assertSame('#badchan', $event->channelName);
                self::assertSame('#badchan', $event->channelNameLower);
                self::assertSame('OperNick', $event->performedBy);
            });

        $result = $this->createService(
            channelRepository: $channelRepository,
            eventPublisher: $eventPublisher,
        )->unforbid('#badchan', 'OperNick');

        self::assertTrue($result);
    }

    #[Test]
    public function unforbidReturnsFalseWhenChannelDoesNotExist(): void
    {
        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn(null);

        $result = $this->createService(channelRepository: $channelRepository)->unforbid('#ghost', 'OperNick');

        self::assertFalse($result);
    }

    #[Test]
    public function unforbidReturnsFalseWhenChannelExistsButIsNotForbidden(): void
    {
        $channel = RegisteredChannel::register('#active', 1, 'Active channel');

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $result = $this->createService(channelRepository: $channelRepository)->unforbid('#active', 'OperNick');

        self::assertFalse($result);
    }

    #[Test]
    public function unforbidDispatchesChannelUnforbiddenEventOnSuccess(): void
    {
        $channel = $this->createForbiddenChannelWithId('#rmchan', 'Toxic', 30);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $dispatchedEvent = null;
        $eventPublisher = $this->createMock(ChanServEventPublisher::class);
        $eventPublisher->expects(self::once())
            ->method('publish')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvent): void {
                $dispatchedEvent = $event;
            });

        $this->createService(
            channelRepository: $channelRepository,
            eventPublisher: $eventPublisher,
        )->unforbid('#rmchan', 'Admin');

        self::assertInstanceOf(ChannelUnforbiddenEvent::class, $dispatchedEvent);
        self::assertSame('#rmchan', $dispatchedEvent->channelName);
        self::assertSame('#rmchan', $dispatchedEvent->channelNameLower);
        self::assertSame('Admin', $dispatchedEvent->performedBy);
    }

    #[Test]
    public function unforbidDoesNotDispatchEventWhenChannelNotFound(): void
    {
        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn(null);

        $eventPublisher = $this->createMock(ChanServEventPublisher::class);
        $eventPublisher->expects(self::never())->method('publish');

        $this->createService(eventPublisher: $eventPublisher)->unforbid('#ghost', 'OperNick');
    }

    #[Test]
    public function unforbidDoesNotDispatchEventWhenChannelNotForbidden(): void
    {
        $channel = RegisteredChannel::register('#active', 1, 'Active');

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $eventPublisher = $this->createMock(ChanServEventPublisher::class);
        $eventPublisher->expects(self::never())->method('publish');

        $this->createService(eventPublisher: $eventPublisher)->unforbid('#active', 'OperNick');
    }

    // --- enforceForbiddenChannel() tests ---

    #[Test]
    public function enforceForbiddenChannelJoinsKicksAndSetsModes(): void
    {
        $channelActions = $this->createMock(ChanNetworkActions::class);
        $channelActions->expects(self::once())
            ->method('isChannelOnNetwork')
            ->with('#bad')
            ->willReturn(true);
        $channelActions->expects(self::once())
            ->method('getChannelTimestamp')
            ->with('#bad')
            ->willReturn(99999);
        $channelActions->expects(self::once())
            ->method('joinChannelAsService')
            ->with('#bad', 99999);
        $channelActions->expects(self::once())
            ->method('getChannelMemberUids')
            ->with('#bad')
            ->willReturn(['UIDAAA', 'UIDAAB', 'UIDAAC']);
        $channelActions->expects(self::exactly(3))
            ->method('kickFromChannel')
            ->willReturnCallback(static function (string $channel, string $uid, string $reason): void {
                self::assertSame('#bad', $channel);
                self::assertSame('Forbidden channel', $reason);
                self::assertContains($uid, ['UIDAAA', 'UIDAAB', 'UIDAAC']);
            });
        $channelActions->expects(self::once())
            ->method('enforceForbiddenModes')
            ->with('#bad', 99999);

        $this->createService(
            channelActions: $channelActions,
        )->enforceForbiddenChannel('#bad');
    }

    #[Test]
    public function enforceForbiddenChannelDoesNothingWhenChannelNotOnNetwork(): void
    {
        $channelActions = $this->createMock(ChanNetworkActions::class);
        $channelActions->expects(self::once())
            ->method('isChannelOnNetwork')
            ->with('#ghostchan')
            ->willReturn(false);
        $channelActions->expects(self::never())->method('joinChannelAsService');
        $channelActions->expects(self::never())->method('kickFromChannel');
        $channelActions->expects(self::never())->method('enforceForbiddenModes');

        $logger = $this->createMock(ChanServActivitySink::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(self::stringContains('not found on network'));

        $this->createService(
            channelActions: $channelActions,
            logger: $logger,
        )->enforceForbiddenChannel('#ghostchan');
    }

    #[Test]
    public function enforceForbiddenChannelKicksEachMemberIndividually(): void
    {
        $channelActions = $this->createMock(ChanNetworkActions::class);
        $channelActions->expects(self::once())
            ->method('isChannelOnNetwork')
            ->with('#kickme')
            ->willReturn(true);
        $channelActions->expects(self::once())
            ->method('getChannelTimestamp')
            ->with('#kickme')
            ->willReturn(55555);
        $channelActions->expects(self::once())
            ->method('joinChannelAsService')
            ->with('#kickme', 55555);
        $channelActions->expects(self::once())
            ->method('getChannelMemberUids')
            ->with('#kickme')
            ->willReturn(['UID001', 'UID002']);

        $kickCalls = [];
        $channelActions->expects(self::exactly(2))
            ->method('kickFromChannel')
            ->willReturnCallback(static function (string $channel, string $uid, string $reason) use (&$kickCalls): void {
                $kickCalls[] = ['channel' => $channel, 'uid' => $uid, 'reason' => $reason];
            });
        $channelActions->expects(self::once())->method('enforceForbiddenModes');

        $this->createService(
            channelActions: $channelActions,
        )->enforceForbiddenChannel('#kickme');

        self::assertCount(2, $kickCalls);
        self::assertSame('UID001', $kickCalls[0]['uid']);
        self::assertSame('UID002', $kickCalls[1]['uid']);
        self::assertSame('Forbidden channel', $kickCalls[0]['reason']);
        self::assertSame('Forbidden channel', $kickCalls[1]['reason']);
    }

    #[Test]
    public function enforceForbiddenChannelSetsModesAfterKicking(): void
    {
        $channelActions = $this->createMock(ChanNetworkActions::class);
        $channelActions->expects(self::once())
            ->method('isChannelOnNetwork')
            ->with('#modes')
            ->willReturn(true);
        $channelActions->expects(self::once())
            ->method('getChannelTimestamp')
            ->with('#modes')
            ->willReturn(11111);

        $callOrder = [];
        $channelActions->expects(self::once())
            ->method('joinChannelAsService')
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = 'join';
            });
        $channelActions->expects(self::once())
            ->method('getChannelMemberUids')
            ->with('#modes')
            ->willReturn([]);
        $channelActions->expects(self::once())
            ->method('enforceForbiddenModes')
            ->with('#modes', 11111)
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = 'setModes';
            });

        $this->createService(
            channelActions: $channelActions,
        )->enforceForbiddenChannel('#modes');

        self::assertSame(['join', 'setModes'], $callOrder);
    }

    #[Test]
    public function publishedForbiddenEnforcementPreservesUntimestampedModeApply(): void
    {
        $actions = $this->createMock(ChanNetworkActions::class);
        $actions->expects(self::once())->method('isChannelOnNetwork')->with('#bad')->willReturn(true);
        $actions->expects(self::once())->method('getChannelTimestamp')->with('#bad')->willReturn(1234);
        $actions->expects(self::once())->method('joinChannelAsService')->with('#bad', 1234);
        $actions->expects(self::once())->method('getChannelMemberUids')->with('#bad')->willReturn(['AAA']);
        $actions->expects(self::once())->method('kickFromChannel')->with('#bad', 'AAA', 'Forbidden channel');
        $actions->expects(self::once())->method('enforceForbiddenModes')->with('#bad');

        $this->createService(channelActions: $actions)->enforcePublishedForbiddenChannel('#bad');
    }

    #[Test]
    public function publishedForbiddenEnforcementSkipsOfflineChannel(): void
    {
        $actions = $this->createMock(ChanNetworkActions::class);
        $actions->expects(self::once())->method('isChannelOnNetwork')->with('#offline')->willReturn(false);
        $actions->expects(self::never())->method('joinChannelAsService');
        $logger = $this->createMock(ChanServActivitySink::class);
        $logger->expects(self::once())->method('debug')->with(self::stringContains('skipping enforcement'));

        $this->createService(channelActions: $actions, logger: $logger)
            ->enforcePublishedForbiddenChannel('#offline');
    }

    #[Test]
    public function releasesUnforbiddenChannelWhenItIsOnline(): void
    {
        $actions = $this->createMock(ChanNetworkActions::class);
        $actions->expects(self::once())->method('isChannelOnNetwork')->with('#released')->willReturn(true);
        $actions->expects(self::once())->method('partChannelAsService')->with('#released');
        $logger = $this->createMock(ChanServActivitySink::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('bot left'));

        $this->createService(channelActions: $actions, logger: $logger)->releaseUnforbiddenChannel('#released');
    }

    #[Test]
    public function releaseUnforbiddenChannelSkipsOfflineChannel(): void
    {
        $actions = $this->createMock(ChanNetworkActions::class);
        $actions->expects(self::once())->method('isChannelOnNetwork')->with('#offline')->willReturn(false);
        $actions->expects(self::never())->method('partChannelAsService');
        $logger = $this->createMock(ChanServActivitySink::class);
        $logger->expects(self::once())->method('debug')->with(self::stringContains('no action needed'));

        $this->createService(channelActions: $actions, logger: $logger)->releaseUnforbiddenChannel('#offline');
    }

    #[Test]
    public function enforceAllForbiddenChannelsSkipsEmptyRepository(): void
    {
        $repository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repository->expects(self::once())->method('findForbiddenChannels')->willReturn([]);
        $actions = $this->createMock(ChanNetworkActions::class);
        $actions->expects(self::never())->method('isChannelOnNetwork');

        $this->createService(channelRepository: $repository, channelActions: $actions)
            ->enforceAllForbiddenChannels();
    }

    #[Test]
    public function enforceAllForbiddenChannelsOnlyEnforcesOnlineEntries(): void
    {
        $online = RegisteredChannel::createForbidden('#online', 'bad');
        $offline = RegisteredChannel::createForbidden('#offline', 'bad');
        $repository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repository->method('findForbiddenChannels')->willReturn([$online, $offline]);
        $actions = $this->createMock(ChanNetworkActions::class);
        $checks = 0;
        $actions->expects(self::exactly(3))->method('isChannelOnNetwork')
            ->willReturnCallback(static function (string $channel) use (&$checks): bool {
                ++$checks;

                return '#online' === $channel;
            });
        $actions->expects(self::once())->method('getChannelTimestamp')->with('#online')->willReturn(42);
        $actions->expects(self::once())->method('joinChannelAsService')->with('#online', 42);
        $actions->expects(self::once())->method('getChannelMemberUids')->with('#online')->willReturn([]);
        $actions->expects(self::once())->method('enforceForbiddenModes')->with('#online', 42);
        $logger = $this->createMock(ChanServActivitySink::class);
        $logger->expects(self::exactly(2))->method('info');
        $logger->expects(self::once())->method('debug')->with(self::stringContains('#offline'));

        $this->createService($repository, channelActions: $actions, logger: $logger)
            ->enforceAllForbiddenChannels();
    }

    #[Test]
    public function forbiddenUserJoinIsIgnoredForUnknownChannel(): void
    {
        $repository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repository->method('findByChannelName')->willReturn(null);
        $actions = $this->createMock(ChanNetworkActions::class);
        $actions->expects(self::never())->method('kickFromChannel');

        $this->createService($repository, channelActions: $actions)
            ->enforceForbiddenUserJoin('#unknown', 'AAA');
    }

    #[Test]
    public function forbiddenUserJoinIsIgnoredForRegularChannel(): void
    {
        $regular = RegisteredChannel::register('#regular', 1, 'regular');
        $repository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repository->method('findByChannelName')->willReturn($regular);
        $actions = $this->createMock(ChanNetworkActions::class);
        $actions->expects(self::never())->method('kickFromChannel');

        $this->createService($repository, channelActions: $actions)
            ->enforceForbiddenUserJoin('#regular', 'AAA');
    }

    #[Test]
    public function forbiddenUserJoinKicksAndReenforcesOnlineChannel(): void
    {
        $forbidden = RegisteredChannel::createForbidden('#bad', 'bad');
        $repository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repository->method('findByChannelName')->willReturn($forbidden);
        $actions = $this->createMock(ChanNetworkActions::class);
        $actions->expects(self::exactly(2))->method('isChannelOnNetwork')->with('#bad')->willReturn(true);
        $actions->expects(self::exactly(2))->method('kickFromChannel')
            ->willReturnCallback(static function (string $channel, string $uid, string $reason): void {
                self::assertSame('#bad', $channel);
                self::assertSame('Forbidden channel', $reason);
                self::assertSame('AAA', $uid);
            });
        $actions->expects(self::once())->method('getChannelTimestamp')->willReturn(42);
        $actions->expects(self::once())->method('joinChannelAsService')->with('#bad', 42);
        $actions->expects(self::once())->method('getChannelMemberUids')->willReturn(['AAA']);
        $actions->expects(self::once())->method('enforceForbiddenModes')->with('#bad', 42);

        $this->createService($repository, channelActions: $actions)
            ->enforceForbiddenUserJoin('#bad', 'AAA');
    }

    #[Test]
    public function forbiddenUserJoinStillKicksWhenChannelSnapshotIsGone(): void
    {
        $forbidden = RegisteredChannel::createForbidden('#bad', 'bad');
        $repository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repository->method('findByChannelName')->willReturn($forbidden);
        $actions = $this->createMock(ChanNetworkActions::class);
        $actions->expects(self::once())->method('kickFromChannel')->with('#bad', 'AAA', 'Forbidden channel');
        $actions->expects(self::once())->method('isChannelOnNetwork')->with('#bad')->willReturn(false);
        $actions->expects(self::never())->method('joinChannelAsService');

        $this->createService($repository, channelActions: $actions)
            ->enforceForbiddenUserJoin('#bad', 'AAA');
    }

    #[Test]
    public function configuredForbiddenChannelIsIgnoredWhenNotForbidden(): void
    {
        $regular = RegisteredChannel::register('#regular', 1, 'regular');
        $repository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repository->method('findByChannelName')->willReturn($regular);
        $actions = $this->createMock(ChanNetworkActions::class);
        $actions->expects(self::never())->method('isChannelOnNetwork');

        $this->createService($repository, channelActions: $actions)
            ->enforceConfiguredForbiddenChannel('#regular');
    }

    #[Test]
    public function configuredForbiddenChannelIsEnforcedOnSynchronization(): void
    {
        $forbidden = RegisteredChannel::createForbidden('#bad', 'bad');
        $repository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repository->method('findByChannelName')->willReturn($forbidden);
        $actions = $this->createMock(ChanNetworkActions::class);
        $actions->expects(self::once())->method('isChannelOnNetwork')->with('#bad')->willReturn(false);
        $logger = $this->createMock(ChanServActivitySink::class);
        $logger->expects(self::once())->method('debug');
        $logger->expects(self::once())->method('info')->with(self::stringContains('on sync'));

        $this->createService($repository, channelActions: $actions, logger: $logger)
            ->enforceConfiguredForbiddenChannel('#bad');
    }
}
