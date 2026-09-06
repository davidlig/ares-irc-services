<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Event;

use App\Irc\Adapter\Event\ConnectionEstablishedEvent;
use App\Irc\Domain\Server\ServerLink;
use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionEstablishedEvent::class)]
final class ConnectionEstablishedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $before = new DateTimeImmutable();
        $serverLink = new ServerLink(
            serverName: new ServerName('services.example.com'),
            host: new Hostname('127.0.0.1'),
            port: new Port(7029),
            password: new LinkPassword('secret'),
            description: 'Ares IRC Services',
            useTls: false,
        );
        $event = new ConnectionEstablishedEvent($serverLink);
        $after = new DateTimeImmutable();

        self::assertSame($serverLink, $event->serverLink);
        self::assertGreaterThanOrEqual($before, $event->occurredAt);
        self::assertLessThanOrEqual($after, $event->occurredAt);
    }
}
