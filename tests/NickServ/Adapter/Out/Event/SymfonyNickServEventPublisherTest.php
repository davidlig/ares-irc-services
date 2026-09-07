<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Event;

use App\Application\Port\EventBusInterface;
use App\NickServ\Adapter\Out\Event\SymfonyNickServEventPublisher;
use App\NickServ\Application\PublishedEvent\UserDeidentifiedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymfonyNickServEventPublisher::class)]
final class SymfonyNickServEventPublisherTest extends TestCase
{
    #[Test]
    public function forwardsPublishedEventsToTheBus(): void
    {
        $event = new UserDeidentifiedEvent('001AAAAAA', 42, 'Tester');
        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->expects(self::once())->method('dispatch')->with($event);

        new SymfonyNickServEventPublisher($eventBus)->publish($event);
    }
}
