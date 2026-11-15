<?php

declare(strict_types=1);

namespace App\Tests\Irc\Application\PublishedEvent;

use App\Irc\Application\PublishedEvent\RemoteBurstEndedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoteBurstEndedEvent::class)]
final class RemoteBurstEndedEventTest extends TestCase
{
    #[Test]
    public function exposesTheSynchronizedServerSid(): void
    {
        self::assertSame('0A2', new RemoteBurstEndedEvent('0A2')->serverSid);
    }
}
