<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Connection;

use Amp\ByteStream\StreamException;
use Amp\Socket\InternetAddress;
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\Socket;
use App\Infrastructure\IRC\Connection\SocketConnection;
use App\Irc\Adapter\Out\Connection\ConnectionStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

use function Amp\async;
use function Amp\delay;
use function Amp\Socket\listen;
use function count;

#[CoversClass(SocketConnection::class)]
final class SocketConnectionTest extends TestCase
{
    /** @var list<string> */
    private array $receivedLines = [];

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

        try {
            $conn->connect();
            self::fail('Expected connection failure exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Failed to connect to 127.0.0.1:59999', $e->getMessage());
            self::assertSame(ConnectionStatus::Error, $conn->getStatus());
        }
    }

    #[Test]
    public function connectWithTlsAndVerifyPeerThrowsWhenConnectionFails(): void
    {
        $conn = new SocketConnection('127.0.0.1', 59999, useTls: true, timeoutSeconds: 1, tlsVerifyPeer: true);

        try {
            $conn->connect();
            self::fail('Expected connection failure exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Failed to connect to 127.0.0.1:59999', $e->getMessage());
            self::assertSame(ConnectionStatus::Error, $conn->getStatus());
        }
    }

    #[Test]
    public function connectWithTlsWithoutVerifyPeerThrowsWhenConnectionFails(): void
    {
        $conn = new SocketConnection('127.0.0.1', 59999, useTls: true, timeoutSeconds: 1, tlsVerifyPeer: false);

        try {
            $conn->connect();
            self::fail('Expected connection failure exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Failed to connect to 127.0.0.1:59999', $e->getMessage());
            self::assertSame(ConnectionStatus::Error, $conn->getStatus());
        }
    }

    #[Test]
    public function disconnectWhenNotConnectedDoesNotThrow(): void
    {
        $conn = new SocketConnection('127.0.0.1', 7000);
        $conn->disconnect();
        self::assertSame(ConnectionStatus::Disconnected, $conn->getStatus());
    }

    #[Test]
    public function disconnectIsIdempotent(): void
    {
        $server = listen('127.0.0.1:0');
        $port = $this->serverPort($server);

        async(static function () use ($server): void {
            $client = $server->accept();
            self::assertNotNull($client);
            $client->close();
            $server->close();
        });

        $conn = new SocketConnection('127.0.0.1', $port, false, 2);
        $conn->connect();

        $conn->disconnect();
        self::assertSame(ConnectionStatus::Disconnected, $conn->getStatus());
        self::assertFalse($conn->isConnected());

        // Repeated disconnect does nothing and does not throw
        $conn->disconnect();
        self::assertSame(ConnectionStatus::Disconnected, $conn->getStatus());
    }

    #[Test]
    public function connectWriteLineReadLineDisconnectWithLocalServer(): void
    {
        $server = listen('127.0.0.1:0');
        $port = $this->serverPort($server);

        async(static function () use ($server): void {
            $client = $server->accept();
            self::assertNotNull($client);
            $line = '';
            while (!str_contains($line, "\n")) {
                $chunk = $client->read();
                if (null === $chunk) {
                    break;
                }
                $line .= $chunk;
            }
            $client->write("PONG 123\r\n");
            $client->close();
            $server->close();
        });

        $conn = new SocketConnection('127.0.0.1', $port, false, 2);
        $conn->connect();

        self::assertTrue($conn->isConnected());
        self::assertSame(ConnectionStatus::Connected, $conn->getStatus());

        $conn->writeLine('PING 123');
        $received = $conn->readLine();
        self::assertSame('PONG 123', $received);

        $conn->disconnect();
        self::assertFalse($conn->isConnected());
        self::assertSame(ConnectionStatus::Disconnected, $conn->getStatus());
        self::assertNull($conn->readLine());
    }

    #[Test]
    public function readLineReturnsNullOnEof(): void
    {
        $server = listen('127.0.0.1:0');
        $port = $this->serverPort($server);

        async(static function () use ($server): void {
            $client = $server->accept();
            self::assertNotNull($client);
            // Close immediately without writing
            $client->close();
            $server->close();
        });

        $conn = new SocketConnection('127.0.0.1', $port, false, 2);
        $conn->connect();

        self::assertTrue($conn->isConnected());

        $line = $conn->readLine();
        self::assertNull($line);
        self::assertFalse($conn->isConnected());

        $conn->disconnect();
    }

    #[Test]
    public function readLineSuspendsUntilDataArrives(): void
    {
        $server = listen('127.0.0.1:0');
        $port = $this->serverPort($server);

        async(static function () use ($server): void {
            $client = $server->accept();
            self::assertNotNull($client);
            delay(0.04);
            $client->write("DELAYED DATA\r\n");
            $client->close();
            $server->close();
        });

        $conn = new SocketConnection('127.0.0.1', $port, false, 2);
        $conn->connect();

        $line = $conn->readLine();
        self::assertSame('DELAYED DATA', $line);

        $conn->disconnect();
    }

    #[Test]
    public function readLineHandlesFragmentationAcrossChunks(): void
    {
        $server = listen('127.0.0.1:0');
        $port = $this->serverPort($server);

        async(static function () use ($server): void {
            $client = $server->accept();
            self::assertNotNull($client);
            $client->write('PART');
            delay(0.01);
            $client->write('IAL ');
            delay(0.01);
            $client->write("DATA\r\n");
            $client->close();
            $server->close();
        });

        $conn = new SocketConnection('127.0.0.1', $port, false, 2);
        $conn->connect();

        $line = $conn->readLine();
        self::assertSame('PARTIAL DATA', $line);

        $conn->disconnect();
    }

    #[Test]
    public function readLineHandlesCrLfSplitAcrossChunks(): void
    {
        $server = listen('127.0.0.1:0');
        $port = $this->serverPort($server);

        async(static function () use ($server): void {
            $client = $server->accept();
            self::assertNotNull($client);
            $client->write("SPLIT\r");
            delay(0.01);
            $client->write("\n");
            $client->close();
            $server->close();
        });

        $conn = new SocketConnection('127.0.0.1', $port, false, 2);
        $conn->connect();

        $line = $conn->readLine();
        self::assertSame('SPLIT', $line);

        $conn->disconnect();
    }

    #[Test]
    public function readLineHandlesMultipleLinesInSingleChunk(): void
    {
        $server = listen('127.0.0.1:0');
        $port = $this->serverPort($server);

        async(static function () use ($server): void {
            $client = $server->accept();
            self::assertNotNull($client);
            $client->write("LINE 1\r\nLINE 2\r\nLINE 3\r\n");
            $client->close();
            $server->close();
        });

        $conn = new SocketConnection('127.0.0.1', $port, false, 2);
        $conn->connect();

        self::assertSame('LINE 1', $conn->readLine());
        self::assertSame('LINE 2', $conn->readLine());
        self::assertSame('LINE 3', $conn->readLine());
        self::assertNull($conn->readLine());

        $conn->disconnect();
    }

    #[Test]
    public function readLineReturnsTrailingDataAtEofWithoutNewline(): void
    {
        $server = listen('127.0.0.1:0');
        $port = $this->serverPort($server);

        async(static function () use ($server): void {
            $client = $server->accept();
            self::assertNotNull($client);
            $client->write('TRAILING DATA');
            $client->close();
            $server->close();
        });

        $conn = new SocketConnection('127.0.0.1', $port, false, 2);
        $conn->connect();

        self::assertSame('TRAILING DATA', $conn->readLine());
        self::assertNull($conn->readLine());

        $conn->disconnect();
    }

    #[Test]
    public function writeLinePreservesOrderForMultipleWrites(): void
    {
        $server = listen('127.0.0.1:0');
        $port = $this->serverPort($server);

        $this->receivedLines = [];
        async(function () use ($server): void {
            $client = $server->accept();
            self::assertNotNull($client);
            $buf = '';
            while (count($this->receivedLines) < 3) {
                $chunk = $client->read();
                if (null === $chunk) {
                    break;
                }
                $buf .= $chunk;
                while (false !== ($pos = strpos($buf, "\n"))) {
                    $this->receivedLines[] = rtrim(substr($buf, 0, $pos), "\r");
                    $buf = substr($buf, $pos + 1);
                }
            }
            $client->close();
            $server->close();
        });

        $conn = new SocketConnection('127.0.0.1', $port, false, 2);
        $conn->connect();

        $conn->writeLine('FIRST');
        $conn->writeLine('SECOND');
        $conn->writeLine('THIRD');

        delay(0.05);

        self::assertSame(['FIRST', 'SECOND', 'THIRD'], $this->receivedLines);

        $conn->disconnect();
    }

    #[Test]
    public function writeLineMarksConnectionAsErroredWhenWriteFails(): void
    {
        $stubSocket = $this->createStub(Socket::class);
        $stubSocket->method('isClosed')->willReturn(false);
        $stubSocket->method('isReadable')->willReturn(true);
        $stubSocket->method('write')->willThrowException(new StreamException('Simulated write failure'));

        $conn = new SocketConnection('127.0.0.1', 7000);

        $statusProp = new ReflectionProperty(SocketConnection::class, 'status');
        $statusProp->setValue($conn, ConnectionStatus::Connected);

        $socketProp = new ReflectionProperty(SocketConnection::class, 'socket');
        $socketProp->setValue($conn, $stubSocket);

        try {
            $conn->writeLine('PING');
            self::fail('Expected write exception');
        } catch (RuntimeException $e) {
            self::assertSame('Failed to write to the IRC connection.', $e->getMessage());
            self::assertSame(ConnectionStatus::Error, $conn->getStatus());
        }
    }

    #[Test]
    public function readLineMarksConnectionAsErroredWhenReadFails(): void
    {
        $stubSocket = $this->createStub(Socket::class);
        $stubSocket->method('isClosed')->willReturn(false);
        $stubSocket->method('isReadable')->willReturn(true);
        $stubSocket->method('read')->willThrowException(new StreamException('Simulated read failure'));

        $conn = new SocketConnection('127.0.0.1', 7000);

        $statusProp = new ReflectionProperty(SocketConnection::class, 'status');
        $statusProp->setValue($conn, ConnectionStatus::Connected);

        $socketProp = new ReflectionProperty(SocketConnection::class, 'socket');
        $socketProp->setValue($conn, $stubSocket);

        try {
            $conn->readLine();
            self::fail('Expected read exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Error reading from IRC connection: Simulated read failure', $e->getMessage());
            self::assertSame(ConnectionStatus::Error, $conn->getStatus());
        }
    }

    private function serverPort(ResourceServerSocket $server): int
    {
        $address = $server->getAddress();
        self::assertInstanceOf(InternetAddress::class, $address);

        return $address->getPort();
    }
}
