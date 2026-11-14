<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Network\ChannelSyncCompletedMarkerSubscriber;
use App\Irc\Adapter\Network\Event\ChannelJoinReceivedEvent;
use App\Irc\Adapter\Network\InMemoryChannelRepository;
use App\Irc\Adapter\Network\InMemoryNetworkUserRepository;
use App\Irc\Adapter\Network\NetworkEventEnricher;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\ChannelSyncCompletedRegistryInterface;
use App\Irc\Application\Port\In\SkipIdentifiedModeStripRegistry;
use App\Irc\Domain\Event\ChannelSyncedEvent;
use App\Irc\Domain\Event\ExistingChannelJoinObservedEvent;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Uid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(ChannelSyncCompletedMarkerSubscriber::class)]
final class ChannelSyncCompletedMarkerSubscriberTest extends TestCase
{
    private ChannelSyncCompletedRegistryInterface&MockObject $registry;

    private ChannelSyncCompletedMarkerSubscriber $subscriber;

    #[Test]
    public function getSubscribedEventsReturnsCorrectEvent(): void
    {
        $this->registry->expects(self::never())->method('markSyncCompleted');

        $events = ChannelSyncCompletedMarkerSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(ChannelSyncedEvent::class, $events);
        self::assertSame(['onChannelSynced', -100], $events[ChannelSyncedEvent::class]);
        self::assertSame(['onExistingChannelJoinObserved', -100], $events[ExistingChannelJoinObservedEvent::class]);
    }

    #[Test]
    public function equalTimestampRejoinAfterNetsplitReArmsTheSyncCompletedMarker(): void
    {
        $dispatcher = new EventDispatcher();
        $channels = new InMemoryChannelRepository();
        $dispatcher->addSubscriber(new NetworkEventEnricher(
            $channels,
            new InMemoryNetworkUserRepository(),
            $dispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistry::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
        ));
        $dispatcher->addSubscriber($this->subscriber);
        $marked = [];
        $this->registry->expects(self::exactly(2))
            ->method('markSyncCompleted')
            ->willReturnCallback(static function (string $channelName) use (&$marked): void {
                $marked[] = $channelName;
            });
        $name = new ChannelName('#test');

        $dispatcher->dispatch(new ChannelJoinReceivedEvent($name, 1700000000, '+nt', [['uid' => new Uid('0A1AAAAAA'), 'role' => ChannelMemberRole::None]]));
        $dispatcher->dispatch(new ChannelJoinReceivedEvent($name, 1700000000, '+nt', [['uid' => new Uid('0A2AAAAAA'), 'role' => ChannelMemberRole::None]]));

        self::assertSame(['#test', '#test'], $marked);
        self::assertSame(1700000000, $channels->findByName($name)?->getCreatedAt()->getTimestamp());
        self::assertSame(2, $channels->findByName($name)->getMemberCount());
    }

    #[Test]
    public function onExistingChannelJoinObservedMarksChannelAsCompleted(): void
    {
        $channel = new Channel(new ChannelName('#test'), '', new DateTimeImmutable('@0'));

        $this->registry->expects(self::once())
            ->method('markSyncCompleted')
            ->with('#test');

        $this->subscriber->onExistingChannelJoinObserved(new ExistingChannelJoinObservedEvent($channel, false));
    }

    #[Test]
    public function onChannelSyncedMarksChannelAsCompletedWhenSetupApplicable(): void
    {
        $channel = new Channel(new ChannelName('#test'), '', new DateTimeImmutable('@0'));

        $event = new ChannelSyncedEvent($channel, true);

        $this->registry->expects(self::once())
            ->method('markSyncCompleted')
            ->with('#test');

        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function onChannelSyncedMarksChannelAsCompletedWhenSetupNotApplicable(): void
    {
        $channel = new Channel(new ChannelName('#test'), '', new DateTimeImmutable('@0'));

        $event = new ChannelSyncedEvent($channel, false);

        $this->registry->expects(self::once())
            ->method('markSyncCompleted')
            ->with('#test');

        $this->subscriber->onChannelSynced($event);
    }

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ChannelSyncCompletedRegistryInterface::class);
        $this->subscriber = new ChannelSyncCompletedMarkerSubscriber($this->registry);
    }
}
