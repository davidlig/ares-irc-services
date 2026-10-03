<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Out\Connection;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Socket\ConnectContext;
use Amp\Socket\InternetAddress;
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketConnector;
use App\Irc\Adapter\Out\Connection\ConnectionStatus;
use App\Irc\Adapter\Out\Connection\SocketConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

use function Amp\async;
use function Amp\delay;
use function Amp\Socket\listen;
use function Amp\Socket\socketConnector;
use function count;
use function strlen;

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

    /** @return iterable<string, array{bool, bool}> */
    public static function connectionOptions(): iterable
    {
        yield 'TCP' => [false, true];
        yield 'TLS with peer verification' => [true, true];
        yield 'TLS without peer verification' => [true, false];
    }

    #[Test]
    #[DataProvider('connectionOptions')]
    public function connectEnablesTcpNoDelayAndPreservesTlsOptions(bool $useTls, bool $verifyPeer): void
    {
        $socket = $this->createMock(Socket::class);
        $socket->expects($useTls ? self::once() : self::never())->method('setupTls')->with(self::isInstanceOf(Cancellation::class));
        $socket->expects(self::once())->method('close');
        $connector = $this->createMock(SocketConnector::class);
        $connector->expects(self::once())->method('connect')->with(
            'example.test:7000',
            self::callback(static function (ConnectContext $context) use ($useTls, $verifyPeer): bool {
                self::assertTrue($context->hasTcpNoDelay());
                self::assertSame(5.0, $context->getConnectTimeout());
                if ($useTls) {
                    $tlsContext = $context->getTlsContext();
                    self::assertNotNull($tlsContext);
                    self::assertSame('example.test', $tlsContext->getPeerName());
                    self::assertSame($verifyPeer, $tlsContext->hasPeerVerification());
                } else {
                    self::assertNull($context->getTlsContext());
                }

                return true;
            }),
            self::isInstanceOf(Cancellation::class),
        )->willReturn($socket);
        $originalConnector = socketConnector();
        socketConnector($connector);
        $connection = new SocketConnection('example.test', 7000, $useTls, 5, $verifyPeer);

        try {
            $connection->connect();
            self::assertSame(ConnectionStatus::Connected, $connection->getStatus());
        } finally {
            socketConnector($originalConnector);
            $connection->disconnect();
        }
    }

    #[Test]
    public function writeLineWithArrayThrowsWhenNotConnected(): void
    {
        $connection = new SocketConnection('127.0.0.1', 7000);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot write: connection is not open.');

        $connection->writeLine(['PING']);
    }

    #[Test]
    public function writeLineWithArrayEmptyBatchDoesNothingEvenWithoutAConnection(): void
    {
        $connection = new SocketConnection('127.0.0.1', 7000);
        $connection->writeLine([]);

        self::assertSame(ConnectionStatus::Disconnected, $connection->getStatus());
    }

    #[Test]
    public function writeLineWithArrayEmptyBatchDoesNotTouchAnOpenSocket(): void
    {
        $socket = $this->createMock(Socket::class);
        $socket->expects(self::never())->method('write');
        $socket->expects(self::never())->method('isClosed');
        $connection = $this->connectionWithSocket($socket);

        $connection->writeLine([]);

        self::assertSame(ConnectionStatus::Connected, $connection->getStatus());
    }

    /** @return iterable<string, array{list<string>, list<string>}> */
    public static function lineBatches(): iterable
    {
        yield 'CRLF and empty and space lines' => [
            ['FIRST', '', ' ', 'THIRD'],
            ["FIRST\r\n\r\n \r\nTHIRD\r\n"],
        ];
        yield 'consecutive empty lines retain every CRLF' => [
            ['', '', ''],
            ["\r\n\r\n\r\n"],
        ];
        $multibyteBoundary = str_repeat('é', 8191);
        yield 'multibyte line reaches byte boundary before following line' => [
            [$multibyteBoundary, 'TAIL'],
            [$multibyteBoundary . "\r\n", "TAIL\r\n"],
        ];
        $multibyteOversized = str_repeat('é', 8192);
        yield 'oversized multibyte line sent alone between ordinary lines' => [
            ['FIRST', $multibyteOversized, 'LAST'],
            ["FIRST\r\n", $multibyteOversized . "\r\n", "LAST\r\n"],
        ];
        $half = str_repeat('A', 8190);
        yield 'exact 16 KiB block including CRLF' => [
            [$half, $half],
            [$half . "\r\n" . $half . "\r\n"],
        ];
        $largerHalf = str_repeat('B', 8191);
        yield 'overflow keeps whole lines' => [
            [$largerHalf, $half],
            [$largerHalf . "\r\n", $half . "\r\n"],
        ];
        $oversized = str_repeat('X', 16383);
        yield 'oversized line sent alone between ordinary lines' => [
            ['FIRST', $oversized, 'LAST'],
            ["FIRST\r\n", $oversized . "\r\n", "LAST\r\n"],
        ];
        yield 'single boundary-sized line' => [
            [str_repeat('Z', 16382)],
            [str_repeat('Z', 16382) . "\r\n"],
        ];
    }

    /**
     * @param list<string> $lines
     * @param list<string> $expectedPayloads
     */
    #[Test]
    #[DataProvider('lineBatches')]
    public function writeLineWithArrayBatchesWithoutSplittingOrChangingAnyLine(array $lines, array $expectedPayloads): void
    {
        $written = [];
        $socket = $this->createMock(Socket::class);
        $socket->method('isClosed')->willReturn(false);
        $socket->method('isReadable')->willReturn(true);
        $socket->expects(self::exactly(count($expectedPayloads)))->method('write')->willReturnCallback(static function (string $payload) use (&$written): void {
            $written[] = $payload;
        });
        $connection = $this->connectionWithSocket($socket);

        $connection->writeLine($lines);

        self::assertSame($expectedPayloads, $written);
        self::assertSame(ConnectionStatus::Connected, $connection->getStatus());
    }

    #[Test]
    public function writeLineWithEmptyStringImmediatelyWritesOneCrLf(): void
    {
        $socket = $this->createMock(Socket::class);
        $socket->method('isClosed')->willReturn(false);
        $socket->method('isReadable')->willReturn(true);
        $socket->expects(self::once())->method('write')->with("\r\n");
        $connection = $this->connectionWithSocket($socket);

        $connection->writeLine('');

        self::assertSame(ConnectionStatus::Connected, $connection->getStatus());
    }

    #[Test]
    public function writeLineRemainsImmediateAndDoesNotChunkAnOversizedLine(): void
    {
        $oversized = str_repeat('X', 32768);
        $written = [];
        $socket = $this->createMock(Socket::class);
        $socket->method('isClosed')->willReturn(false);
        $socket->method('isReadable')->willReturn(true);
        $socket->expects(self::exactly(2))->method('write')->willReturnCallback(static function (string $payload) use (&$written): void {
            $written[] = $payload;
        });
        $connection = $this->connectionWithSocket($socket);

        $connection->writeLine($oversized);
        self::assertSame($oversized . "\r\n", implode('', $written));
        $connection->writeLine('NEXT');
        self::assertSame([$oversized . "\r\n", "NEXT\r\n"], $written);
    }

    #[Test]
    public function writeLinePreservesOrderAcrossStringAndArrayArguments(): void
    {
        $written = [];
        $socket = $this->createMock(Socket::class);
        $socket->method('isClosed')->willReturn(false);
        $socket->method('isReadable')->willReturn(true);
        $socket->expects(self::exactly(3))->method('write')->willReturnCallback(static function (string $payload) use (&$written): void {
            $written[] = $payload;
        });
        $connection = $this->connectionWithSocket($socket);

        $connection->writeLine('FIRST');
        self::assertSame("FIRST\r\n", implode('', $written));
        $connection->writeLine(['SECOND', 'THIRD']);
        self::assertSame("FIRST\r\nSECOND\r\nTHIRD\r\n", implode('', $written));
        $connection->writeLine('LAST');

        self::assertSame(["FIRST\r\n", "SECOND\r\nTHIRD\r\n", "LAST\r\n"], $written);
    }

    #[Test]
    public function writeLineWithArrayRejectsAClosedSocket(): void
    {
        $socket = $this->createMock(Socket::class);
        $socket->method('isClosed')->willReturn(true);
        $socket->expects(self::never())->method('write');
        $connection = $this->connectionWithSocket($socket);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot write: connection is not open.');

        $connection->writeLine(['PING']);
    }

    #[Test]
    public function writeLineWithArrayStopsSynchronouslyOnFailureWithoutRetryingOrSendingRemainingLines(): void
    {
        $failure = new StreamException('Simulated batch failure');
        $sent = [];
        $socket = $this->createMock(Socket::class);
        $socket->method('isClosed')->willReturn(false);
        $socket->method('isReadable')->willReturn(true);
        $socket->expects(self::once())->method('close');
        $socket->expects(self::exactly(2))->method('write')->willReturnCallback(static function (string $payload) use (&$sent, $failure): void {
            if ([] !== $sent) {
                throw $failure;
            }
            $sent[] = $payload;
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Failed to write to the IRC connection.', ['error' => $failure->getMessage()]);
        $connection = $this->connectionWithSocket($socket, $logger);
        $fullLine = str_repeat('A', 16382);

        try {
            $connection->writeLine([$fullLine, $fullLine, 'NEVER SENT']);
            self::fail('Expected batch write failure');
        } catch (RuntimeException $exception) {
            self::assertSame('Failed to write to the IRC connection.', $exception->getMessage());
            self::assertSame($failure, $exception->getPrevious());
            self::assertSame(ConnectionStatus::Error, $connection->getStatus());
            self::assertSame([$fullLine . "\r\n"], $sent);
        }

        $connection->disconnect();
        $newWrites = [];
        $newSocket = $this->createMock(Socket::class);
        $newSocket->method('isClosed')->willReturn(false);
        $newSocket->method('isReadable')->willReturn(true);
        $newSocket->expects(self::once())->method('close');
        $newSocket->expects(self::once())->method('write')->with("NEW SESSION\r\n")->willReturnCallback(static function (string $payload) use (&$newWrites): void {
            $newWrites[] = $payload;
        });
        $connector = $this->createMock(SocketConnector::class);
        $connector->expects(self::once())->method('connect')->willReturn($newSocket);
        $originalConnector = socketConnector();
        socketConnector($connector);

        try {
            $connection->connect();
            self::assertSame('', implode('', $newWrites), 'Reconnect must not replay failed or unsent lines');
            $connection->writeLine('NEW SESSION');
            self::assertSame(["NEW SESSION\r\n"], $newWrites);
        } finally {
            socketConnector($originalConnector);
            $connection->disconnect();
        }
    }

    #[Test]
    public function writeLineWithArrayWaitsForSocketBackpressureBeforeTheNextChunk(): void
    {
        $started = new DeferredFuture();
        $release = new DeferredFuture();
        $written = [];
        $socket = $this->createMock(Socket::class);
        $socket->method('isClosed')->willReturn(false);
        $socket->method('isReadable')->willReturn(true);
        $socket->expects(self::exactly(2))->method('write')->willReturnCallback(static function (string $payload) use (&$written, $started, $release): void {
            if ([] === $written) {
                $started->complete();
                $release->getFuture()->await();
            }
            $written[] = $payload;
        });
        $connection = $this->connectionWithSocket($socket);
        $fullLine = str_repeat('A', 16382);
        $writer = async(static function () use ($connection, $fullLine): void {
            $connection->writeLine([$fullLine, 'SECOND']);
        });

        $started->getFuture()->await();
        self::assertFalse($writer->isComplete());
        self::assertSame('', implode('', $written));
        $release->complete();
        $writer->await();

        self::assertSame([$fullLine . "\r\n", "SECOND\r\n"], $written);
    }

    #[Test]
    public function writeLineWithArrayPreservesBytesWithALocalReceiver(): void
    {
        $server = listen('127.0.0.1:0');
        $payload = "FIRST\r\n \r\nLAST\r\n";
        $receiver = async(static function () use ($server, $payload): string {
            $client = $server->accept();
            self::assertNotNull($client);
            try {
                $received = '';
                while (strlen($received) < strlen($payload)) {
                    $chunk = $client->read();
                    self::assertNotNull($chunk);
                    $received .= $chunk;
                }

                return $received;
            } finally {
                $client->close();
            }
        });
        $connection = new SocketConnection('127.0.0.1', $this->serverPort($server), false, 2);

        try {
            $connection->connect();
            $connection->writeLine(['FIRST', ' ', 'LAST']);
            self::assertSame($payload, $receiver->await());
        } finally {
            $connection->disconnect();
            $server->close();
        }
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

    private function connectionWithSocket(Socket $socket, ?LoggerInterface $logger = null): SocketConnection
    {
        $connection = null === $logger
            ? new SocketConnection('127.0.0.1', 7000)
            : new SocketConnection('127.0.0.1', 7000, logger: $logger);
        new ReflectionProperty(SocketConnection::class, 'status')->setValue($connection, ConnectionStatus::Connected);
        new ReflectionProperty(SocketConnection::class, 'socket')->setValue($connection, $socket);

        return $connection;
    }

    private function serverPort(ResourceServerSocket $server): int
    {
        $address = $server->getAddress();
        self::assertInstanceOf(InternetAddress::class, $address);

        return $address->getPort();
    }
}
