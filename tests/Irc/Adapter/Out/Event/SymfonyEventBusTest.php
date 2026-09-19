<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Out\Event;

use App\Irc\Adapter\Out\Event\SymfonyEventBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(SymfonyEventBus::class)]
final class SymfonyEventBusTest extends TestCase
{
    #[Test]
    public function dispatchDelegatesToSymfonyEventDispatcher(): void
    {
        $event = new stdClass();
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($event)
            ->willReturn($event);

        $eventBus = new SymfonyEventBus($eventDispatcher);

        $eventBus->dispatch($event);
    }
}
