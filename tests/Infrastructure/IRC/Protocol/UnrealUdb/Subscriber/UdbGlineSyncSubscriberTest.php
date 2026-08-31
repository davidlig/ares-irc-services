<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber;

use App\Application\Port\UdbRecordWriterInterface;
use App\Domain\OperServ\Event\GlineRemovedEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber\UdbGlineSyncSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbGlineSyncSubscriber::class)]
final class UdbGlineSyncSubscriberTest extends TestCase
{
    #[Test]
    public function subscribedEventsIncludeGlineRemoved(): void
    {
        self::assertSame(
            [GlineRemovedEvent::class => 'onGlineRemoved'],
            UdbGlineSyncSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function glineRemovalDeletesTheWholeKBlockPattern(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('delete')->with('K', 'G::*@bad.example')->willReturn(true);

        $subscriber = new UdbGlineSyncSubscriber($writer);
        $subscriber->onGlineRemoved(new GlineRemovedEvent(5, '*@bad.example', 'ares-services.davidlig.net', 'expired'));
    }
}
