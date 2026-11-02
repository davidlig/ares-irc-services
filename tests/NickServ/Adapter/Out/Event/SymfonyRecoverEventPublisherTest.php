<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Event;

use App\NickServ\Adapter\Out\Event\SymfonyRecoverEventPublisher;
use App\NickServ\Application\Port\In\NickServEvent;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\Shared\Application\Port\EventBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymfonyRecoverEventPublisher::class)]
final class SymfonyRecoverEventPublisherTest extends TestCase
{
    #[Test]
    public function forwardsEventsToTheEventBus(): void
    {
        $event = new NickPasswordHashAvailable(42, 'Tester', 'hash');
        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->expects(self::once())->method('dispatch')->with($event);

        new SymfonyRecoverEventPublisher($eventBus)->publish($event);
    }

    #[Test]
    public function acceptsContextEventsWithoutAConcreteTypeUnion(): void
    {
        $event = new class implements NickServEvent {};
        $bus = $this->createMock(EventBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::identicalTo($event));
        new SymfonyRecoverEventPublisher($bus)->publish($event);
    }
}
