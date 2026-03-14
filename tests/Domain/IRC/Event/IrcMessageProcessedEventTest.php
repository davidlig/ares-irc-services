<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\IrcMessageProcessedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcMessageProcessedEvent::class)]
final class IrcMessageProcessedEventTest extends TestCase
{
    #[Test]
    public function canBeConstructed(): void
    {
        $event = new IrcMessageProcessedEvent();

        self::assertInstanceOf(IrcMessageProcessedEvent::class, $event);
    }
}
