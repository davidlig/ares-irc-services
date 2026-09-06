<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Event;

use App\Application\Port\EventBusInterface;
use App\NickServ\Adapter\Out\Event\SymfonyRecoverEventPublisher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(SymfonyRecoverEventPublisher::class)]
final class SymfonyRecoverEventPublisherTest extends TestCase
{
    #[Test]
    public function forwardsEventsToTheEventBus(): void
    {
        $event = new stdClass();
        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->expects(self::once())->method('dispatch')->with($event);

        new SymfonyRecoverEventPublisher($eventBus)->publish($event);
    }
}
