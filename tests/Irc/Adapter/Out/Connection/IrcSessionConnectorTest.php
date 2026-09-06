<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Out\Connection;

use App\Irc\Adapter\Out\Connection\IrcSessionConnector;
use App\Irc\Adapter\Runtime\IRCClient;
use App\Irc\Adapter\Runtime\IRCClientFactoryInterface;
use App\Irc\Domain\Server\ServerLink;
use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcSessionConnector::class)]
final class IrcSessionConnectorTest extends TestCase
{
    #[Test]
    public function itCreatesConnectsAndReturnsTheSession(): void
    {
        $serverLink = new ServerLink(
            new ServerName('services.test.local'),
            new Hostname('127.0.0.1'),
            new Port(7029),
            new LinkPassword('secret'),
            'Ares Test',
        );
        $client = $this->createMock(IRCClient::class);
        $client->expects(self::once())->method('connect')->with($serverLink);
        $factory = $this->createMock(IRCClientFactoryInterface::class);
        $factory->expects(self::once())->method('create')->with('unreal', $serverLink)->willReturn($client);

        $session = new IrcSessionConnector($factory)->connect('unreal', $serverLink);

        self::assertSame($client, $session);
    }
}
