<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Event;

use App\Irc\Adapter\Event\ConnectionLostEvent;
use App\Irc\Domain\Server\ServerLink;
use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionLostEvent::class)]
final class ConnectionLostEventTest extends TestCase
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
        $event = new ConnectionLostEvent($serverLink, 'Connection reset');
        $after = new DateTimeImmutable();

        self::assertSame($serverLink, $event->serverLink);
        self::assertSame('Connection reset', $event->reason);
        self::assertGreaterThanOrEqual($before, $event->occurredAt);
        self::assertLessThanOrEqual($after, $event->occurredAt);
    }

    #[Test]
    public function reasonCanBeNull(): void
    {
        $serverLink = new ServerLink(
            serverName: new ServerName('s.example.com'),
            host: new Hostname('127.0.0.1'),
            port: new Port(7029),
            password: new LinkPassword('x'),
            description: 'd',
            useTls: false,
        );
        $event = new ConnectionLostEvent($serverLink, null);

        self::assertNull($event->reason);
    }
}
