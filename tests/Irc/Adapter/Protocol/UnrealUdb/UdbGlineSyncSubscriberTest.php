<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbGlineSyncSubscriber;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordWriterInterface;
use App\OperServ\Application\PublishedEvent\GlineRemovedEvent;
use DateTimeImmutable;
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
        $subscriber->onGlineRemoved(new GlineRemovedEvent(5, '*@bad.example', 'ares-services.davidlig.net', 'expired', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
    }
}
