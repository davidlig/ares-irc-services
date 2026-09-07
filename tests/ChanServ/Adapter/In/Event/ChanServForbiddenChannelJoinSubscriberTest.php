<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\Application\Port\ChannelServiceActionsPort;
use App\ChanServ\Adapter\In\Event\ChanServForbiddenChannelJoinSubscriber;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChannelForbiddenService;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\PublishedEvent\ChannelSynchronizedEvent;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServForbiddenChannelJoinSubscriber::class)]
final class ChanServForbiddenChannelJoinSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToCorrectEvents(): void
    {
        self::assertSame(
            [
                UserJoinedChannelEvent::class => ['onUserJoinedChannel', 10],
                ChannelSynchronizedEvent::class => ['onChannelSynced', 10],
            ],
            ChanServForbiddenChannelJoinSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function onUserJoinedChannelKicksAndEnforcesWhenChannelIsForbidden(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isForbidden')->willReturn(true);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('findByChannelName')->with('#forbidden')->willReturn($channel);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('kickFromChannel')->with('#forbidden', 'AAA123', 'Forbidden channel');

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::never())->method('enforceForbiddenChannel');

        $event = new UserJoinedChannelEvent('AAA123', '#forbidden');

        $subscriber = new ChanServForbiddenChannelJoinSubscriber(
            $channelRepository,
            $channelServiceActions,
            $channelLookup,
            $forbiddenService,
        );
        $subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function onUserJoinedChannelEnforcesForbiddenWhenChannelOnNetwork(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isForbidden')->willReturn(true);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('findByChannelName')->with('#forbidden')->willReturn($channel);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('kickFromChannel');

        $view = new ChannelView('#forbidden', '+nt', null, 1);
        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#forbidden')->willReturn($view);

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::once())->method('enforceForbiddenChannel')->with('#forbidden');

        $event = new UserJoinedChannelEvent('AAA123', '#forbidden');

        $subscriber = new ChanServForbiddenChannelJoinSubscriber(
            $channelRepository,
            $channelServiceActions,
            $channelLookup,
            $forbiddenService,
        );
        $subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function onUserJoinedChannelDoesNothingWhenChannelIsNotForbidden(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isForbidden')->willReturn(false);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('findByChannelName')->with('#regular')->willReturn($channel);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::never())->method('enforceForbiddenChannel');

        $event = new UserJoinedChannelEvent('AAA123', '#regular');

        $subscriber = new ChanServForbiddenChannelJoinSubscriber(
            $channelRepository,
            $channelServiceActions,
            $channelLookup,
            $forbiddenService,
        );
        $subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function onUserJoinedChannelDoesNothingWhenChannelDoesNotExistInRepo(): void
    {
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('findByChannelName')->with('#unknown')->willReturn(null);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('kickFromChannel');

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::never())->method('enforceForbiddenChannel');

        $event = new UserJoinedChannelEvent('AAA123', '#unknown');

        $subscriber = new ChanServForbiddenChannelJoinSubscriber(
            $channelRepository,
            $channelServiceActions,
            $channelLookup,
            $forbiddenService,
        );
        $subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function onChannelSyncedEnforcesForbiddenChannelWhenSetupApplicable(): void
    {
        $registeredChannel = $this->createStub(RegisteredChannel::class);
        $registeredChannel->method('isForbidden')->willReturn(true);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('findByChannelName')->with('#forbidden')->willReturn($registeredChannel);

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::once())->method('enforceForbiddenChannel')->with('#forbidden');

        $event = new ChannelSynchronizedEvent('#forbidden');

        $subscriber = new ChanServForbiddenChannelJoinSubscriber(
            $channelRepository,
            $this->createStub(ChannelServiceActionsPort::class),
            $this->createStub(ChannelLookupPort::class),
            $forbiddenService,
        );
        $subscriber->onChannelSynced($event);
    }

    #[Test]
    public function onChannelSyncedEnforcesForbiddenEvenWhenChannelSetupNotApplicable(): void
    {
        $registeredChannel = $this->createStub(RegisteredChannel::class);
        $registeredChannel->method('isForbidden')->willReturn(true);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('findByChannelName')->with('#forbidden')->willReturn($registeredChannel);

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::once())->method('enforceForbiddenChannel')->with('#forbidden');

        $event = new ChannelSynchronizedEvent('#forbidden');

        $subscriber = new ChanServForbiddenChannelJoinSubscriber(
            $channelRepository,
            $this->createStub(ChannelServiceActionsPort::class),
            $this->createStub(ChannelLookupPort::class),
            $forbiddenService,
        );
        $subscriber->onChannelSynced($event);
    }

    #[Test]
    public function onChannelSyncedDoesNothingWhenChannelIsNotForbidden(): void
    {
        $registeredChannel = $this->createStub(RegisteredChannel::class);
        $registeredChannel->method('isForbidden')->willReturn(false);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('findByChannelName')->with('#regular')->willReturn($registeredChannel);

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::never())->method('enforceForbiddenChannel');

        $event = new ChannelSynchronizedEvent('#regular');

        $subscriber = new ChanServForbiddenChannelJoinSubscriber(
            $channelRepository,
            $this->createStub(ChannelServiceActionsPort::class),
            $this->createStub(ChannelLookupPort::class),
            $forbiddenService,
        );
        $subscriber->onChannelSynced($event);
    }

    #[Test]
    public function onChannelSyncedDoesNothingWhenChannelNotInRepo(): void
    {
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('findByChannelName')->with('#unknown')->willReturn(null);

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::never())->method('enforceForbiddenChannel');

        $event = new ChannelSynchronizedEvent('#unknown');

        $subscriber = new ChanServForbiddenChannelJoinSubscriber(
            $channelRepository,
            $this->createStub(ChannelServiceActionsPort::class),
            $this->createStub(ChannelLookupPort::class),
            $forbiddenService,
        );
        $subscriber->onChannelSynced($event);
    }
}
