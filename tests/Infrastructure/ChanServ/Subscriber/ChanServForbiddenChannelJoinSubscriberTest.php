<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\Service\ChannelForbiddenService;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\ChannelSyncedEvent;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;
use App\Infrastructure\ChanServ\Subscriber\ChanServForbiddenChannelJoinSubscriber;
use DateTimeImmutable;
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
                ChannelSyncedEvent::class => ['onChannelSynced', 10],
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

        $event = new UserJoinedChannelEvent(
            uid: new Uid('AAA123'),
            channel: new ChannelName('#forbidden'),
            role: ChannelMemberRole::None,
        );

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

        $view = new \App\Application\Port\ChannelView('#forbidden', '+nt', null, 1);
        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#forbidden')->willReturn($view);

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::once())->method('enforceForbiddenChannel')->with('#forbidden');

        $event = new UserJoinedChannelEvent(
            uid: new Uid('AAA123'),
            channel: new ChannelName('#forbidden'),
            role: ChannelMemberRole::None,
        );

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

        $event = new UserJoinedChannelEvent(
            uid: new Uid('AAA123'),
            channel: new ChannelName('#regular'),
            role: ChannelMemberRole::None,
        );

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

        $event = new UserJoinedChannelEvent(
            uid: new Uid('AAA123'),
            channel: new ChannelName('#unknown'),
            role: ChannelMemberRole::None,
        );

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

        $ircChannel = new Channel(
            name: new ChannelName('#forbidden'),
            modes: '+nt',
            createdAt: new DateTimeImmutable('@1775962977'),
        );

        $event = new ChannelSyncedEvent($ircChannel, channelSetupApplicable: true);

        $subscriber = new ChanServForbiddenChannelJoinSubscriber(
            $channelRepository,
            $this->createStub(ChannelServiceActionsPort::class),
            $this->createStub(ChannelLookupPort::class),
            $forbiddenService,
        );
        $subscriber->onChannelSynced($event);
    }

    #[Test]
    public function onChannelSyncedDoesNothingWhenChannelSetupNotApplicable(): void
    {
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::never())->method('findByChannelName');

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::never())->method('enforceForbiddenChannel');

        $ircChannel = new Channel(
            name: new ChannelName('#forbidden'),
            modes: '+nt',
            createdAt: new DateTimeImmutable('@1775962977'),
        );

        $event = new ChannelSyncedEvent($ircChannel, channelSetupApplicable: false);

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

        $ircChannel = new Channel(
            name: new ChannelName('#regular'),
            modes: '+nt',
            createdAt: new DateTimeImmutable('@1775962977'),
        );

        $event = new ChannelSyncedEvent($ircChannel, channelSetupApplicable: true);

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

        $ircChannel = new Channel(
            name: new ChannelName('#unknown'),
            modes: '+nt',
            createdAt: new DateTimeImmutable('@1775962977'),
        );

        $event = new ChannelSyncedEvent($ircChannel, channelSetupApplicable: true);

        $subscriber = new ChanServForbiddenChannelJoinSubscriber(
            $channelRepository,
            $this->createStub(ChannelServiceActionsPort::class),
            $this->createStub(ChannelLookupPort::class),
            $forbiddenService,
        );
        $subscriber->onChannelSynced($event);
    }
}
