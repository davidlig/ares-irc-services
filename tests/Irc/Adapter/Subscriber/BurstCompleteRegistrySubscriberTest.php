<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Subscriber;

use App\Irc\Adapter\Event\ConnectionEstablishedEvent;
use App\Irc\Adapter\Event\ConnectionLostEvent;
use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Subscriber\BurstCompleteRegistrySubscriber;
use App\Irc\Application\BurstCompleteRegistry;
use App\Irc\Domain\Server\ServerLink;
use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BurstCompleteRegistrySubscriber::class)]
final class BurstCompleteRegistrySubscriberTest extends TestCase
{
    private BurstCompleteRegistry $registry;

    private BurstCompleteRegistrySubscriber $subscriber;

    protected function setUp(): void
    {
        $this->registry = new BurstCompleteRegistry();
        $this->subscriber = new BurstCompleteRegistrySubscriber($this->registry);
    }

    #[Test]
    public function getSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = BurstCompleteRegistrySubscriber::getSubscribedEvents();

        self::assertArrayHasKey(ConnectionEstablishedEvent::class, $events);
        self::assertArrayHasKey(NetworkBurstCompleteEvent::class, $events);
        self::assertArrayHasKey(ConnectionLostEvent::class, $events);
    }

    #[Test]
    public function onConnectionEstablishedSetsBurstCompleteFalse(): void
    {
        $serverLink = $this->createServerLink();
        $event = new ConnectionEstablishedEvent($serverLink);

        $this->registry->setBurstComplete(true);
        self::assertTrue($this->registry->isBurstComplete());

        $this->subscriber->onConnectionEstablished($event);

        self::assertFalse($this->registry->isBurstComplete());
    }

    #[Test]
    public function onBurstCompleteSetsBurstCompleteTrue(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');

        self::assertFalse($this->registry->isBurstComplete());

        $this->subscriber->onBurstComplete($event);

        self::assertTrue($this->registry->isBurstComplete());
    }

    #[Test]
    public function onConnectionLostSetsBurstCompleteFalse(): void
    {
        $serverLink = $this->createServerLink();
        $event = new ConnectionLostEvent($serverLink, 'Connection reset');

        $this->registry->setBurstComplete(true);
        self::assertTrue($this->registry->isBurstComplete());

        $this->subscriber->onConnectionLost($event);

        self::assertFalse($this->registry->isBurstComplete());
    }

    private function createServerLink(): ServerLink
    {
        return new ServerLink(
            new ServerName('irc.example.com'),
            new Hostname('192.168.1.1'),
            new Port(7000),
            new LinkPassword('secret'),
            'Test Server',
            true,
        );
    }
}
