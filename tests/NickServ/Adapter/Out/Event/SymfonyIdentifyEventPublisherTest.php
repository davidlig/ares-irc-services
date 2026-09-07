<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Event;

use App\Application\Port\EventBusInterface;
use App\NickServ\Adapter\Out\Event\SymfonyIdentifyEventPublisher;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymfonyIdentifyEventPublisher::class)]
final class SymfonyIdentifyEventPublisherTest extends TestCase
{
    #[Test]
    public function dispatchesEventToBus(): void
    {
        $bus = $this->createMock(EventBusInterface::class);
        $event = new NickPasswordHashAvailable(42, 'Tester', 'hash');
        $bus->expects(self::once())->method('dispatch')->with($event);

        $publisher = new SymfonyIdentifyEventPublisher($bus);
        $publisher->publish($event);
    }
}
