<?php

declare(strict_types=1);

namespace App\Tests\Irc\Application\PublishedEvent;

use App\Irc\Application\PublishedEvent\ServerDelinkFinishedEvent;
use App\Irc\Application\PublishedEvent\ServerDelinkStartedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerDelinkStartedEvent::class)]
#[CoversClass(ServerDelinkFinishedEvent::class)]
final class ServerDelinkEventsTest extends TestCase
{
    #[Test]
    public function exposeTheDelinkedServerSid(): void
    {
        self::assertSame('0A2', new ServerDelinkStartedEvent('0A2')->serverSid);
        self::assertSame('0A2', new ServerDelinkFinishedEvent('0A2')->serverSid);
    }
}
