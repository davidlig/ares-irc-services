<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Event;

use App\Application\Port\EventBusInterface;
use App\NickServ\Adapter\Out\Event\SymfonyRegistrationEventPublisher;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymfonyRegistrationEventPublisher::class)]
final class SymfonyRegistrationEventPublisherTest extends TestCase
{
    #[Test]
    public function publishesTheSafeIntegrationEvent(): void
    {
        $event = new NickPasswordHashAvailable(null, 'Nick', 'hash');
        $bus = $this->createMock(EventBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with($event);

        new SymfonyRegistrationEventPublisher($bus)->publish($event);
    }
}
