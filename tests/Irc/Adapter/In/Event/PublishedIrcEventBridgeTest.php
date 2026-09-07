<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\In\Event;

use App\Irc\Adapter\Event\IrcMessageProcessedEvent;
use App\Irc\Adapter\Event\MessageReceivedEvent;
use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Irc\Adapter\In\Event\PublishedIrcEventBridge;
use App\Irc\Adapter\Network\Event\ChannelTopicReceivedEvent;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Application\PublishedEvent\ChannelSettingsChangedEvent;
use App\Irc\Application\PublishedEvent\ChannelSynchronizedEvent;
use App\Irc\Application\PublishedEvent\ChannelTopicReceivedEvent as PublishedChannelTopicReceivedEvent;
use App\Irc\Application\PublishedEvent\IrcMessageHandledEvent;
use App\Irc\Application\PublishedEvent\IrcMessageHandlingStartedEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\ServiceIntroductionRequestedEvent;
use App\Irc\Application\PublishedEvent\UserDepartedChannelEvent;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent as PublishedUserJoinedChannelEvent;
use App\Irc\Application\PublishedEvent\UserLeftNetworkEvent;
use App\Irc\Application\PublishedEvent\UserModesChangedEvent;
use App\Irc\Application\PublishedEvent\UserNicknameChangedEvent;
use App\Irc\Domain\Event\ChannelModesChangedEvent;
use App\Irc\Domain\Event\ChannelSyncedEvent;
use App\Irc\Domain\Event\UserJoinedChannelEvent;
use App\Irc\Domain\Event\UserLeftChannelEvent;
use App\Irc\Domain\Event\UserModeChangedEvent;
use App\Irc\Domain\Event\UserNickChangedEvent;
use App\Irc\Domain\Event\UserQuitNetworkEvent;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(PublishedIrcEventBridge::class)]
#[CoversClass(ChannelSettingsChangedEvent::class)]
#[CoversClass(ChannelSynchronizedEvent::class)]
#[CoversClass(PublishedChannelTopicReceivedEvent::class)]
#[CoversClass(IrcMessageHandledEvent::class)]
#[CoversClass(IrcMessageHandlingStartedEvent::class)]
#[CoversClass(NetworkSynchronizationCompletedEvent::class)]
#[CoversClass(ServiceIntroductionRequestedEvent::class)]
#[CoversClass(PublishedUserJoinedChannelEvent::class)]
#[CoversClass(UserDepartedChannelEvent::class)]
#[CoversClass(UserLeftNetworkEvent::class)]
#[CoversClass(UserModesChangedEvent::class)]
#[CoversClass(UserNicknameChangedEvent::class)]
final class PublishedIrcEventBridgeTest extends TestCase
{
    #[Test]
    public function declaresStablePublicationPriorities(): void
    {
        self::assertSame([
            MessageReceivedEvent::class => ['publishMessageHandlingStarted', 256],
            UserNickChangedEvent::class => ['publishNicknameChanged', -10],
            UserModeChangedEvent::class => ['publishModesChanged', -10],
            UserQuitNetworkEvent::class => ['publishUserLeft', -10],
            UserJoinedChannelEvent::class => ['publishUserJoinedChannel', -10],
            UserLeftChannelEvent::class => ['publishUserDepartedChannel', 10],
            ChannelSyncedEvent::class => ['publishChannelSynchronized', -4],
            ChannelTopicReceivedEvent::class => ['publishChannelTopicReceived', 0],
            ChannelModesChangedEvent::class => ['publishChannelSettingsChanged', -1],
            NetworkBurstCompleteEvent::class => [
                ['publishServiceIntroductionRequested', 100],
            ],
            NetworkSyncCompleteEvent::class => ['publishNetworkSynchronizationCompleted', -256],
            IrcMessageProcessedEvent::class => ['publishMessageHandled', -200],
        ], PublishedIrcEventBridge::getSubscribedEvents());
    }

    #[Test]
    public function publishesMessageStartMarker(): void
    {
        $bridge = $this->bridgeExpecting(static fn (object $event): bool => $event instanceof IrcMessageHandlingStartedEvent);

        $bridge->publishMessageHandlingStarted();
    }

    #[Test]
    public function publishesNicknameChangeAsScalars(): void
    {
        $bridge = $this->bridgeExpecting(static fn (object $event): bool => $event instanceof UserNicknameChangedEvent
            && '001ABC' === $event->uid
            && 'OldNick' === $event->oldNickname
            && 'NewNick' === $event->newNickname);

        $bridge->publishNicknameChanged(new UserNickChangedEvent(new Uid('001ABC'), new Nick('OldNick'), new Nick('NewNick')));
    }

    #[Test]
    public function publishesModeChangeAsScalars(): void
    {
        $bridge = $this->bridgeExpecting(static fn (object $event): bool => $event instanceof UserModesChangedEvent
            && '001ABC' === $event->uid
            && '+r' === $event->modeDelta);

        $bridge->publishModesChanged(new UserModeChangedEvent(new Uid('001ABC'), '+r'));
    }

    #[Test]
    public function publishesQuitWithCompleteSnapshot(): void
    {
        $bridge = $this->bridgeExpecting(static fn (object $event): bool => $event instanceof UserLeftNetworkEvent
            && ['001ABC', 'Nick', 'Quit', 'ident', 'display', 'host', 'ip'] === [
                $event->uid,
                $event->nickname,
                $event->reason,
                $event->ident,
                $event->displayHost,
                $event->hostname,
                $event->ipBase64,
            ]);

        $bridge->publishUserLeft(new UserQuitNetworkEvent(
            new Uid('001ABC'),
            new Nick('Nick'),
            'Quit',
            'ident',
            'display',
            'host',
            'ip',
        ));
    }

    #[Test]
    public function publishesChannelJoinAsScalars(): void
    {
        $bridge = $this->bridgeExpecting(static fn (object $event): bool => $event instanceof PublishedUserJoinedChannelEvent
            && '001ABC' === $event->uid
            && '#test' === $event->channelName
            && 'op' === $event->initialRole);

        $bridge->publishUserJoinedChannel(new UserJoinedChannelEvent(
            new Uid('001ABC'),
            new ChannelName('#test'),
            ChannelMemberRole::Op,
        ));
    }

    #[Test]
    public function publishesChannelLifecycleEventsAsScalars(): void
    {
        $events = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::exactly(3))->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$events): object {
                $events[] = $event;

                return $event;
            },
        );
        $bridge = new PublishedIrcEventBridge($dispatcher);
        $channel = new Channel(new ChannelName('#test'));

        $bridge->publishUserDepartedChannel(new UserLeftChannelEvent(
            new Uid('001ABC'),
            new Nick('Alice'),
            new ChannelName('#test'),
            'Leaving',
            false,
        ));
        $bridge->publishChannelSynchronized(new ChannelSyncedEvent($channel));
        $bridge->publishChannelSettingsChanged(new ChannelModesChangedEvent($channel));

        self::assertInstanceOf(UserDepartedChannelEvent::class, $events[0]);
        self::assertSame(['001ABC', '#test'], [$events[0]->uid, $events[0]->channelName]);
        self::assertInstanceOf(ChannelSynchronizedEvent::class, $events[1]);
        self::assertSame('#test', $events[1]->channelName);
        self::assertInstanceOf(ChannelSettingsChangedEvent::class, $events[2]);
        self::assertSame('#test', $events[2]->channelName);
    }

    #[Test]
    public function publishesServiceIntroductionRequestWithoutConnection(): void
    {
        $bridge = $this->bridgeExpecting(static fn (object $event): bool => $event instanceof ServiceIntroductionRequestedEvent
            && '001' === $event->serverSid);

        $bridge->publishServiceIntroductionRequested($this->burstEvent());
    }

    #[Test]
    public function publishesNetworkSynchronizationCompletionWithoutConnection(): void
    {
        $bridge = $this->bridgeExpecting(static fn (object $event): bool => $event instanceof NetworkSynchronizationCompletedEvent
            && '001' === $event->serverSid);

        $bridge->publishNetworkSynchronizationCompleted(new NetworkSyncCompleteEvent(
            $this->createStub(ConnectionInterface::class),
            '001',
        ));
    }

    #[Test]
    public function publishesChannelTopicReceivedAsScalars(): void
    {
        $bridge = $this->bridgeExpecting(static fn (object $event): bool => $event instanceof PublishedChannelTopicReceivedEvent
            && '#test' === $event->channelName
            && 'Welcome to #test' === $event->topic
            && 'Alice' === $event->setterNick
            && '001ABC' === $event->sourceUid);

        $bridge->publishChannelTopicReceived(new ChannelTopicReceivedEvent(
            new ChannelName('#test'),
            'Welcome to #test',
            'Alice',
            '001ABC',
        ));
    }

    #[Test]
    public function publishesMessageHandledMarker(): void
    {
        $bridge = $this->bridgeExpecting(static fn (object $event): bool => $event instanceof IrcMessageHandledEvent);

        $bridge->publishMessageHandled(new IrcMessageProcessedEvent());
    }

    /** @param callable(object): bool $matches */
    private function bridgeExpecting(callable $matches): PublishedIrcEventBridge
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::callback($matches))->willReturnArgument(0);

        return new PublishedIrcEventBridge($dispatcher);
    }

    private function burstEvent(): NetworkBurstCompleteEvent
    {
        return new NetworkBurstCompleteEvent($this->createStub(ConnectionInterface::class), '001');
    }
}
