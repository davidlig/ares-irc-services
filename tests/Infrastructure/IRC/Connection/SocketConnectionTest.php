<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Connection;

use App\Domain\IRC\Connection\ConnectionStatus;
use App\Infrastructure\IRC\Connection\SocketConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SocketConnection::class)]
final class SocketConnectionTest extends TestCase
{
    #[Test]
    public function getStatusReturnsDisconnectedBeforeConnect(): void
    {
        $conn = new SocketConnection('127.0.0.1', 7000, false, 5);
        self::assertSame(ConnectionStatus::Disconnected, $conn->getStatus());
    }

    #[Test]
    public function isConnectedReturnsFalseBeforeConnect(): void
    {
        $conn = new SocketConnection('127.0.0.1', 7000);
        self::assertFalse($conn->isConnected());
    }

    #[Test]
    public function readLineReturnsNullWhenNotConnected(): void
    {
        $conn = new SocketConnection('127.0.0.1', 7000);
        self::assertNull($conn->readLine());
    }

    #[Test]
    public function writeLineThrowsWhenNotConnected(): void
    {
        $conn = new SocketConnection('127.0.0.1', 7000);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot write: connection is not open.');
        $conn->writeLine('PING');
    }

    #[Test]
    public function connectThrowsWhenConnectionFails(): void
    {
        $conn = new SocketConnection('127.0.0.1', 59999, false, 1);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to connect to');

        $conn->connect();
    }

    #[Test]
    public function disconnectWhenNotConnectedDoesNotThrow(): void
    {
        $conn = new SocketConnection('127.0.0.1', 7000);
        $conn->disconnect();
        self::assertSame(ConnectionStatus::Disconnected, $conn->getStatus());
    }

    /**
     * Connects to a local TCP server, writes a line, reads it back, disconnects.
     * Requires no external network; server runs in-process.
     */
    #[Test]
    #[Group('integration')]
    public function connectWriteLineReadLineDisconnectWithLocalServer(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, "Failed to create server: $errstr ($errno)");
        $addr = stream_socket_get_name($server, false);
        self::assertNotFalse($addr);
        [$host, $port] = explode(':', $addr);

        $conn = new SocketConnection($host, (int) $port, false, 2);
        $conn->connect();

        self::assertTrue($conn->isConnected());
        self::assertSame(ConnectionStatus::Connected, $conn->getStatus());

        $client = stream_socket_accept($server, 2.0);
        self::assertNotFalse($client);
        $conn->writeLine('PING 123');
        $received = fgets($client);
        self::assertSame("PING 123\r\n", $received);

        fwrite($client, "PONG 123\r\n");
        fclose($client);
        $line = $conn->readLine();
        self::assertSame('PONG 123', $line);

        $conn->disconnect();
        self::assertFalse($conn->isConnected());
        self::assertSame(ConnectionStatus::Disconnected, $conn->getStatus());
        self::assertNull($conn->readLine());

        fclose($server);
    }
}
