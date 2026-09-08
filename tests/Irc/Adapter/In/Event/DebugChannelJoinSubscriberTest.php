<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\In\Event;

use App\ChanServ\Application\Port\In\RegisteredChannelSetup;
use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Irc\Adapter\In\Event\DebugChannelJoinSubscriber;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Shared\Application\Port\ServiceDebugNotifierInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DebugChannelJoinSubscriber::class)]
final class DebugChannelJoinSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToBurstAndNetworkSynchronization(): void
    {
        self::assertSame([
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 0],
            NetworkSyncCompleteEvent::class => ['onSyncComplete', -30],
        ], DebugChannelJoinSubscriber::getSubscribedEvents());
    }

    #[Test]
    public function asksEveryNotifierToJoinAfterBurst(): void
    {
        $first = $this->createMock(ServiceDebugNotifierInterface::class);
        $first->expects(self::once())->method('ensureChannelJoined');
        $second = $this->createMock(ServiceDebugNotifierInterface::class);
        $second->expects(self::once())->method('ensureChannelJoined');

        $subscriber = new DebugChannelJoinSubscriber(
            [$first, $second],
            null,
            $this->createStub(RegisteredChannelSetup::class),
        );

        $subscriber->onBurstComplete(new NetworkBurstCompleteEvent(
            $this->createStub(ConnectionInterface::class),
            '001',
        ));
    }

    #[Test]
    public function acceptsAnEmptyNotifierCollection(): void
    {
        self::expectNotToPerformAssertions();

        $subscriber = new DebugChannelJoinSubscriber(
            [],
            null,
            $this->createStub(RegisteredChannelSetup::class),
        );

        $subscriber->onBurstComplete(new NetworkBurstCompleteEvent(
            $this->createStub(ConnectionInterface::class),
            '001',
        ));
    }

    #[Test]
    public function ignoresMissingDebugChannelAfterNetworkSynchronization(): void
    {
        $setup = $this->createMock(RegisteredChannelSetup::class);
        $setup->expects(self::never())->method('restore');
        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');

        new DebugChannelJoinSubscriber([], null, $setup)->onSyncComplete($event);
        new DebugChannelJoinSubscriber([], '', $setup)->onSyncComplete($event);
    }

    #[Test]
    public function delegatesConfiguredChannelSetupAfterNetworkSynchronization(): void
    {
        $setup = $this->createMock(RegisteredChannelSetup::class);
        $setup->expects(self::once())->method('restore')->with('#opers');
        $subscriber = new DebugChannelJoinSubscriber([], '#opers', $setup);

        $subscriber->onSyncComplete(new NetworkSyncCompleteEvent(
            $this->createStub(ConnectionInterface::class),
            '001',
        ));
    }
}
