<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Network\ChannelSyncCompletedMarkerSubscriber;
use App\Irc\Adapter\Network\InMemoryChannelRepository;
use App\Irc\Adapter\Network\InMemoryNetworkUserRepository;
use App\Irc\Adapter\Network\NetworkEventEnricher;
use App\Irc\Adapter\Network\ServerBurstRegistry;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneNetworkStateAdapter;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\ChannelSyncCompletedRegistryInterface;
use App\Irc\Application\Port\In\SkipIdentifiedModeStripRegistry;
use App\Irc\Domain\Event\ChannelSyncedEvent;
use App\Irc\Domain\Event\ExistingChannelJoinObservedEvent;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\ValueObject\ChannelName;
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
        $adapter = new UnrealStandaloneNetworkStateAdapter($dispatcher, burstRegistry: new ServerBurstRegistry());

        // Linked network with #test; leaf 002 splits and is reintroduced, bursting the same channel TS.
        foreach ([
            'PROTOCTL SID=001',
            ':001 SID leaf1.example.net 2 002 :Leaf 1',
            ':001 SJOIN 1700000000 #test +nt :001AAAAAA',
            ':002 EOS',
            ':001 EOS',
            ':001 SQUIT 002 :Split',
            ':001 SID leaf1.example.net 2 002 :Leaf 1',
            ':002 SJOIN 1700000000 #test +nt :002AAAAAA',
            ':002 EOS',
        ] as $line) {
            $adapter->handleMessage(IRCMessage::fromRawLine($line));
        }

        self::assertSame(['#test', '#test'], $marked);
        self::assertSame(1700000000, $channels->findByName($name)?->getCreatedAt()->getTimestamp());
        self::assertSame(2, $channels->findByName($name)->getMemberCount());
    }

    #[Test]
    public function onExistingChannelJoinObservedFromABurstingServerMarksChannelAsCompleted(): void
    {
        $channel = new Channel(new ChannelName('#test'), '', new \DateTimeImmutable('@0'));

        $this->registry->expects(self::once())
            ->method('markSyncCompleted')
            ->with('#test');

        $this->subscriber->onExistingChannelJoinObserved(new ExistingChannelJoinObservedEvent($channel, false, fromBurstingServer: true));
    }

    #[Test]
    public function onExistingChannelJoinObservedFromANonBurstingServerDoesNotReArm(): void
    {
        $channel = new Channel(new ChannelName('#test'), '', new \DateTimeImmutable('@0'));

        $this->registry->expects(self::never())->method('markSyncCompleted');

        $this->subscriber->onExistingChannelJoinObserved(new ExistingChannelJoinObservedEvent($channel, false));
    }

    #[Test]
    public function onChannelSyncedMarksChannelAsCompletedWhenSetupApplicable(): void
    {
        $channel = new Channel(new ChannelName('#test'), '', new \DateTimeImmutable('@0'));

        $event = new ChannelSyncedEvent($channel, true);

        $this->registry->expects(self::once())
            ->method('markSyncCompleted')
            ->with('#test');

        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function onChannelSyncedMarksChannelAsCompletedWhenSetupNotApplicable(): void
    {
        $channel = new Channel(new ChannelName('#test'), '', new \DateTimeImmutable('@0'));

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
