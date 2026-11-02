<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Event;

use App\NickServ\Adapter\Out\Event\SymfonyNickServEventPublisher;
use App\NickServ\Application\Port\In\NickServEvent;
use App\NickServ\Application\PublishedEvent\UserDeidentifiedEvent;
use App\Shared\Application\Port\EventBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;
use TypeError;

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

    #[Test]
    public function acceptsNewNicknameEventsWithoutEditingThePublisher(): void
    {
        $event = new class implements NickServEvent {};
        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->expects(self::once())->method('dispatch')->with(self::identicalTo($event));
        new SymfonyNickServEventPublisher($eventBus)->publish($event);
    }

    #[Test]
    public function rejectsUnrelatedObjectsBeforeDispatch(): void
    {
        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->expects(self::never())->method('dispatch');
        $this->expectException(TypeError::class);
        new ReflectionMethod(SymfonyNickServEventPublisher::class, 'publish')->invoke(new SymfonyNickServEventPublisher($eventBus), new stdClass());
    }
}
