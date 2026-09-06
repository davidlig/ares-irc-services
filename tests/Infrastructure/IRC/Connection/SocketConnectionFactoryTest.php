<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Connection;

use App\Infrastructure\IRC\Connection\SocketConnection;
use App\Infrastructure\IRC\Connection\SocketConnectionFactory;
use App\Irc\Domain\Server\ServerLink;
use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(SocketConnectionFactory::class)]
final class SocketConnectionFactoryTest extends TestCase
{
    private SocketConnectionFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new SocketConnectionFactory(30);
    }

    #[Test]
    public function createReturnsSocketConnection(): void
    {
        $link = new ServerLink(
            new ServerName('irc.test.local'),
            new Hostname('127.0.0.1'),
            new Port(7000),
            new LinkPassword('secret'),
            'Test',
            false,
        );

        $connection = $this->factory->create($link);

        self::assertInstanceOf(SocketConnection::class, $connection);

        $refTls = new ReflectionProperty(SocketConnection::class, 'useTls');
        $refVerify = new ReflectionProperty(SocketConnection::class, 'tlsVerifyPeer');
        self::assertFalse($refTls->getValue($connection));
        self::assertTrue($refVerify->getValue($connection));
    }

    #[Test]
    public function createPassesTlsAndVerifyPeerOptions(): void
    {
        $link = new ServerLink(
            new ServerName('irc.test.local'),
            new Hostname('127.0.0.1'),
            new Port(7000),
            new LinkPassword('secret'),
            'Test',
            true,
            false,
        );

        $connection = $this->factory->create($link);

        self::assertInstanceOf(SocketConnection::class, $connection);

        $refTls = new ReflectionProperty(SocketConnection::class, 'useTls');
        $refVerify = new ReflectionProperty(SocketConnection::class, 'tlsVerifyPeer');
        self::assertTrue($refTls->getValue($connection));
        self::assertFalse($refVerify->getValue($connection));
    }
}
