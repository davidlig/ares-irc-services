<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\ConnectionEstablishedEvent;
use App\Domain\IRC\Server\ServerLink;
use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionEstablishedEvent::class)]
final class ConnectionEstablishedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $serverLink = new ServerLink(
            serverName: new ServerName('services.example.com'),
            host: new Hostname('127.0.0.1'),
            port: new Port(7029),
            password: new LinkPassword('secret'),
            description: 'Ares IRC Services',
            useTls: false,
        );
        $event = new ConnectionEstablishedEvent($serverLink);

        self::assertSame($serverLink, $event->serverLink);
        self::assertNotNull($event->occurredAt);
    }
}
