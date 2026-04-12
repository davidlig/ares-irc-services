<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Service;

use App\Application\ChanServ\Service\ChanDropService;
use App\Application\ChanServ\Service\ChannelForbiddenService;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelForbiddenEvent;
use App\Domain\ChanServ\Event\ChannelUnforbiddenEvent;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(ChannelForbiddenService::class)]
final class ChannelForbiddenServiceTest extends TestCase
{
    private RegisteredChannelRepositoryInterface $channelRepository;

    private ChanDropService $dropService;

    private ChannelServiceActionsPort $channelServiceActions;

    private ChannelLookupPort $channelLookup;

    private EventDispatcherInterface $eventDispatcher;

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $this->dropService = $this->createStub(ChanDropService::class);
        $this->channelServiceActions = $this->createStub(ChannelServiceActionsPort::class);
        $this->channelLookup = $this->createStub(ChannelLookupPort::class);
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function createService(
        ?RegisteredChannelRepositoryInterface $channelRepository = null,
        ?ChanDropService $dropService = null,
        ?ChannelServiceActionsPort $channelServiceActions = null,
        ?ChannelLookupPort $channelLookup = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?LoggerInterface $logger = null,
    ): ChannelForbiddenService {
        return new ChannelForbiddenService(
            $channelRepository ?? $this->channelRepository,
            $dropService ?? $this->dropService,
            $channelServiceActions ?? $this->channelServiceActions,
            $channelLookup ?? $this->channelLookup,
            $eventDispatcher ?? $this->eventDispatcher,
            $logger ?? $this->logger,
        );
    }

    private function createForbiddenChannelWithId(string $channelName, string $reason, int $id): RegisteredChannel
    {
        $channel = RegisteredChannel::createForbidden($channelName, $reason);

        $reflection = new ReflectionClass(RegisteredChannel::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($channel, $id);

        return $channel;
    }

    private function createRegisteredChannelWithId(string $channelName, int $founderId, string $desc, int $id): RegisteredChannel
    {
        $channel = RegisteredChannel::register($channelName, $founderId, $desc);

        $reflection = new ReflectionClass(RegisteredChannel::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($channel, $id);

        return $channel;
    }

    private function repositoryThatSavesAndSetsId(): RegisteredChannelRepositoryInterface
    {
        $repository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repository->method('save')
            ->willReturnCallback(static function (RegisteredChannel $channel): void {
                $reflection = new ReflectionClass(RegisteredChannel::class);
                $idProp = $reflection->getProperty('id');
                $idProp->setAccessible(true);

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
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvent): object {
                $dispatchedEvent = $event;

                return $event;
            });

        $result = $this->createService(
            channelRepository: $channelRepository,
            eventDispatcher: $eventDispatcher,
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
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvent): object {
                $dispatchedEvent = $event;

                return $event;
            });

        $result = $this->createService(
            channelRepository: $channelRepository,
            dropService: $dropService,
            eventDispatcher: $eventDispatcher,
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

        $view = new ChannelView('#taken', '+nt', 'Topic', 0, [], 12345);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($view);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())
            ->method('joinChannelAsService')
            ->with('#taken', 12345);
        $channelServiceActions->expects(self::once())
            ->method('setChannelModes')
            ->with('#taken', '+ntims', []);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $this->createService(
            channelRepository: $channelRepository,
            dropService: $dropService,
            channelServiceActions: $channelServiceActions,
            channelLookup: $channelLookup,
            eventDispatcher: $eventDispatcher,
        )->forbid('#taken', 'Forbidden', 'OperNick');
    }

    #[Test]
    public function forbidDispatchesChannelForbiddenEvent(): void
    {
        $channelRepository = $this->repositoryThatSavesAndSetsId();
        $channelRepository->method('findByChannelName')->willReturn(null);

        $dispatchedEvent = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvent): object {
                $dispatchedEvent = $event;

                return $event;
            });

        $result = $this->createService(
            channelRepository: $channelRepository,
            eventDispatcher: $eventDispatcher,
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

        $view = new ChannelView('#enforce', '+nt', 'Topic', 3, [], 12345);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($view);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())
            ->method('joinChannelAsService')
            ->with('#enforce', 12345);
        $channelServiceActions->expects(self::once())
            ->method('setChannelModes')
            ->with('#enforce', '+ntims', []);

        $this->createService(
            channelRepository: $channelRepository,
            channelServiceActions: $channelServiceActions,
            channelLookup: $channelLookup,
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

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('joinChannelAsService');
        $channelServiceActions->expects(self::never())->method('kickFromChannel');
        $channelServiceActions->expects(self::never())->method('setChannelModes');

        $this->createService(
            channelRepository: $channelRepository,
            channelServiceActions: $channelServiceActions,
        )->forbid('#already', 'Updated', 'Oper');
    }

    #[Test]
    public function forbidLogsInfoWhenCreatingNewForbidden(): void
    {
        $channelRepository = $this->repositoryThatSavesAndSetsId();
        $channelRepository->method('findByChannelName')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
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

        $logger = $this->createMock(LoggerInterface::class);
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
        $logger = $this->createMock(LoggerInterface::class);
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

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event): object {
                self::assertInstanceOf(ChannelUnforbiddenEvent::class, $event);
                self::assertSame('#badchan', $event->channelName);
                self::assertSame('#badchan', $event->channelNameLower);
                self::assertSame('OperNick', $event->performedBy);

                return $event;
            });

        $result = $this->createService(
            channelRepository: $channelRepository,
            eventDispatcher: $eventDispatcher,
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
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvent): object {
                $dispatchedEvent = $event;

                return $event;
            });

        $this->createService(
            channelRepository: $channelRepository,
            eventDispatcher: $eventDispatcher,
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

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $this->createService(eventDispatcher: $eventDispatcher)->unforbid('#ghost', 'OperNick');
    }

    #[Test]
    public function unforbidDoesNotDispatchEventWhenChannelNotForbidden(): void
    {
        $channel = RegisteredChannel::register('#active', 1, 'Active');

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $this->createService(eventDispatcher: $eventDispatcher)->unforbid('#active', 'OperNick');
    }

    // --- enforceForbiddenChannel() tests ---

    #[Test]
    public function enforceForbiddenChannelJoinsKicksAndSetsModes(): void
    {
        $members = [
            ['uid' => 'UIDAAA', 'roleLetter' => 'o'],
            ['uid' => 'UIDAAB', 'roleLetter' => 'v'],
            ['uid' => 'UIDAAC', 'roleLetter' => ''],
        ];
        $view = new ChannelView('#bad', '+nt', 'Topic', 3, $members, 99999);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($view);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())
            ->method('joinChannelAsService')
            ->with('#bad', 99999);
        $channelServiceActions->expects(self::exactly(3))
            ->method('kickFromChannel')
            ->willReturnCallback(static function (string $channel, string $uid, string $reason): void {
                self::assertSame('#bad', $channel);
                self::assertSame('Forbidden channel', $reason);
                self::assertContains($uid, ['UIDAAA', 'UIDAAB', 'UIDAAC']);
            });
        $channelServiceActions->expects(self::once())
            ->method('setChannelModes')
            ->with('#bad', '+ntims', []);

        $this->createService(
            channelServiceActions: $channelServiceActions,
            channelLookup: $channelLookup,
        )->enforceForbiddenChannel('#bad');
    }

    #[Test]
    public function enforceForbiddenChannelDoesNothingWhenChannelNotOnNetwork(): void
    {
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('joinChannelAsService');
        $channelServiceActions->expects(self::never())->method('kickFromChannel');
        $channelServiceActions->expects(self::never())->method('setChannelModes');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(self::stringContains('not found on network'));

        $this->createService(
            channelServiceActions: $channelServiceActions,
            channelLookup: $channelLookup,
            logger: $logger,
        )->enforceForbiddenChannel('#ghostchan');
    }

    #[Test]
    public function enforceForbiddenChannelKicksEachMemberIndividually(): void
    {
        $members = [
            ['uid' => 'UID001', 'roleLetter' => 'o'],
            ['uid' => 'UID002', 'roleLetter' => ''],
        ];
        $view = new ChannelView('#kickme', '+nt', 'Topic', 2, $members, 55555);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($view);

        $kickCalls = [];
        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('joinChannelAsService');
        $channelServiceActions->expects(self::exactly(2))
            ->method('kickFromChannel')
            ->willReturnCallback(static function (string $channel, string $uid, string $reason) use (&$kickCalls): void {
                $kickCalls[] = ['channel' => $channel, 'uid' => $uid, 'reason' => $reason];
            });
        $channelServiceActions->expects(self::once())->method('setChannelModes');

        $this->createService(
            channelServiceActions: $channelServiceActions,
            channelLookup: $channelLookup,
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
        $view = new ChannelView('#modes', '+nt', 'Topic', 0, [], 11111);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($view);

        $callOrder = [];
        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())
            ->method('joinChannelAsService')
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = 'join';
            });
        $channelServiceActions->expects(self::once())
            ->method('setChannelModes')
            ->with('#modes', '+ntims', [])
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = 'setModes';
            });

        $this->createService(
            channelServiceActions: $channelServiceActions,
            channelLookup: $channelLookup,
        )->enforceForbiddenChannel('#modes');

        self::assertSame(['join', 'setModes'], $callOrder);
    }
}
