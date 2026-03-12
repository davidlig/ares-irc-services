<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Server;

use App\Domain\IRC\Server\ServerLink;
use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerLink::class)]
final class ServerLinkTest extends TestCase
{
    #[Test]
    public function holdsAllLinkParameters(): void
    {
        $link = new ServerLink(
            serverName: new ServerName('services.example.com'),
            host: new Hostname('127.0.0.1'),
            port: new Port(7029),
            password: new LinkPassword('secret'),
            description: 'Ares IRC Services',
            useTls: true,
        );

        self::assertSame('services.example.com', $link->serverName->value);
        self::assertSame('127.0.0.1', $link->host->value);
        self::assertSame(7029, $link->port->value);
        self::assertSame('secret', $link->password->value);
        self::assertSame('Ares IRC Services', $link->description);
        self::assertTrue($link->useTls);
    }

    #[Test]
    public function useTlsDefaultsToFalse(): void
    {
        $link = new ServerLink(
            serverName: new ServerName('s.example.com'),
            host: new Hostname('127.0.0.1'),
            port: new Port(7029),
            password: new LinkPassword('p'),
            description: 'Desc',
        );

        self::assertFalse($link->useTls);
    }
}
