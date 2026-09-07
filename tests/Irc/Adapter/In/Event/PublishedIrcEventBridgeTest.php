<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\In\Event;

use App\Irc\Adapter\Event\IrcMessageProcessedEvent;
use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\In\Event\PublishedIrcEventBridge;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Application\PublishedEvent\IrcMessageHandledEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\ServiceIntroductionRequestedEvent;
use App\Irc\Application\PublishedEvent\UserLeftNetworkEvent;
use App\Irc\Application\PublishedEvent\UserModesChangedEvent;
use App\Irc\Application\PublishedEvent\UserNicknameChangedEvent;
use App\Irc\Domain\Event\UserModeChangedEvent;
use App\Irc\Domain\Event\UserNickChangedEvent;
use App\Irc\Domain\Event\UserQuitNetworkEvent;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(PublishedIrcEventBridge::class)]
#[CoversClass(IrcMessageHandledEvent::class)]
#[CoversClass(NetworkSynchronizationCompletedEvent::class)]
#[CoversClass(ServiceIntroductionRequestedEvent::class)]
#[CoversClass(UserLeftNetworkEvent::class)]
#[CoversClass(UserModesChangedEvent::class)]
#[CoversClass(UserNicknameChangedEvent::class)]
final class PublishedIrcEventBridgeTest extends TestCase
{
    #[Test]
    public function declaresStablePublicationPriorities(): void
    {
        self::assertSame([
            UserNickChangedEvent::class => ['publishNicknameChanged', -10],
            UserModeChangedEvent::class => ['publishModesChanged', -10],
            UserQuitNetworkEvent::class => ['publishUserLeft', -10],
            NetworkBurstCompleteEvent::class => [
                ['publishServiceIntroductionRequested', 100],
                ['publishNetworkSynchronizationCompleted', -256],
            ],
            IrcMessageProcessedEvent::class => ['publishMessageHandled', -200],
        ], PublishedIrcEventBridge::getSubscribedEvents());
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

        $bridge->publishNetworkSynchronizationCompleted($this->burstEvent());
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
