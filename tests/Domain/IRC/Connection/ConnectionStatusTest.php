<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Connection;

use App\Domain\IRC\Connection\ConnectionStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionStatus::class)]
final class ConnectionStatusTest extends TestCase
{
    #[Test]
    public function allCasesExist(): void
    {
        self::assertInstanceOf(ConnectionStatus::class, ConnectionStatus::Disconnected);
        self::assertInstanceOf(ConnectionStatus::class, ConnectionStatus::Connecting);
        self::assertInstanceOf(ConnectionStatus::class, ConnectionStatus::Connected);
        self::assertInstanceOf(ConnectionStatus::class, ConnectionStatus::Authenticating);
        self::assertInstanceOf(ConnectionStatus::class, ConnectionStatus::Authenticated);
        self::assertInstanceOf(ConnectionStatus::class, ConnectionStatus::Error);
    }
}
