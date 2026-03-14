<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Network;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Message\MessageDirection;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Network\SyncCompleteDispatcherSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(SyncCompleteDispatcherSubscriber::class)]
final class SyncCompleteDispatcherSubscriberTest extends TestCase
{
    private ActiveConnectionHolder $connectionHolder;

    private EventDispatcherInterface $eventDispatcher;

    private SyncCompleteDispatcherSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->connectionHolder = new ActiveConnectionHolder();
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $this->subscriber = new SyncCompleteDispatcherSubscriber($this->connectionHolder, $this->eventDispatcher);
    }

    #[Test]
    public function getSubscribedEventsReturnsMessageReceivedAtPriorityMinus10(): void
    {
        $events = SyncCompleteDispatcherSubscriber::getSubscribedEvents();
        self::assertArrayHasKey(MessageReceivedEvent::class, $events);
        self::assertSame(['onMessageReceived', -10], $events[MessageReceivedEvent::class]);
    }

    #[Test]
    public function onMessageReceivedDoesNothingWhenCommandIsNotEosOrEndburst(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->expects(self::never())->method('dispatch');
        $this->subscriber = new SyncCompleteDispatcherSubscriber($this->connectionHolder, $this->eventDispatcher);

        $msg = new IRCMessage('PRIVMSG', '', ['#chan'], 'hi', MessageDirection::Incoming);
        $event = new MessageReceivedEvent($msg);
        $this->subscriber->onMessageReceived($event);
    }

    #[Test]
    public function onMessageReceivedDoesNothingWhenConnectionOrSidMissing(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->expects(self::never())->method('dispatch');
        $this->subscriber = new SyncCompleteDispatcherSubscriber($this->connectionHolder, $this->eventDispatcher);

        $msg = new IRCMessage('EOS', '', [], '', MessageDirection::Incoming);
        $event = new MessageReceivedEvent($msg);
        $this->subscriber->onMessageReceived($event);
    }

    #[Test]
    public function onMessageReceivedDispatchesNetworkSyncCompleteWhenEosAndConnectionSet(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $msg = new IRCMessage('EOS', '', [], '', MessageDirection::Incoming);
        $event = new MessageReceivedEvent($msg);
        $dispatched = null;
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched = $event;

                return $event;
            });
        $this->subscriber = new SyncCompleteDispatcherSubscriber($this->connectionHolder, $this->eventDispatcher);
        $this->subscriber->onMessageReceived($event);

        self::assertInstanceOf(NetworkSyncCompleteEvent::class, $dispatched);
        self::assertSame($connection, $dispatched->connection);
        self::assertSame('001', $dispatched->serverSid);
    }

    #[Test]
    public function onMessageReceivedDispatchesNetworkSyncCompleteWhenEndburstAndConnectionSet(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '002'));
        $msg = new IRCMessage('ENDBURST', '', [], '', MessageDirection::Incoming);
        $event = new MessageReceivedEvent($msg);
        $dispatched = null;
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $e) use (&$dispatched): object {
                $dispatched = $e;

                return $e;
            });
        $this->subscriber = new SyncCompleteDispatcherSubscriber($this->connectionHolder, $this->eventDispatcher);
        $this->subscriber->onMessageReceived($event);
        self::assertInstanceOf(NetworkSyncCompleteEvent::class, $dispatched);
        self::assertSame('002', $dispatched->serverSid);
    }
}
