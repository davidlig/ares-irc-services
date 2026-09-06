<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Event;

use App\Application\Port\EventBusInterface;
use App\NickServ\Adapter\Out\Event\SymfonyIdentifyEventPublisher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(SymfonyIdentifyEventPublisher::class)]
final class SymfonyIdentifyEventPublisherTest extends TestCase
{
    #[Test]
    public function dispatchesEventToBus(): void
    {
        $bus = $this->createMock(EventBusInterface::class);
        $event = new stdClass();
        $bus->expects(self::once())->method('dispatch')->with($event);

        $publisher = new SymfonyIdentifyEventPublisher($bus);
        $publisher->publish($event);
    }
}
