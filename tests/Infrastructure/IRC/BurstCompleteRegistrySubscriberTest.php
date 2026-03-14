<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC;

use App\Application\IRC\BurstCompleteRegistry;
use App\Domain\IRC\Event\ConnectionEstablishedEvent;
use App\Domain\IRC\Event\ConnectionLostEvent;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Server\ServerLink;
use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;
use App\Infrastructure\IRC\BurstCompleteRegistrySubscriber;
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
        $connection = $this->createMock(\App\Domain\IRC\Connection\ConnectionInterface::class);
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
