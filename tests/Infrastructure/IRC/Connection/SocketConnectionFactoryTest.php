<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Connection;

use App\Domain\IRC\Server\ServerLink;
use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;
use App\Infrastructure\IRC\Connection\SocketConnection;
use App\Infrastructure\IRC\Connection\SocketConnectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SocketConnectionFactory::class)]
final class SocketConnectionFactoryTest extends TestCase
{
    private SocketConnectionFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new SocketConnectionFactory(30);
    }

    #[Test]
    public function create_returnsSocketConnection(): void
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
    }
}
