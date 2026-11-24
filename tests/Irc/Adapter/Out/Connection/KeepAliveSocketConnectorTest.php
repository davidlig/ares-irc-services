<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Out\Connection;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Amp\Dns\DnsResolver;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\InternetAddress;
use Amp\Socket\ResourceSocket;
use Amp\Socket\UnixAddress;
use Amp\TimeoutCancellation;
use App\Irc\Adapter\Out\Connection\KeepAliveSocketConnector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use function Amp\delay;
use function fclose;
use function restore_error_handler;
use function set_error_handler;
use function socket_get_option;
use function socket_import_stream;
use function sprintf;
use function stream_context_create;
use function stream_socket_accept;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_server;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const SO_KEEPALIVE;
use const SOL_SOCKET;
use const SOL_TCP;
use const TCP_KEEPCNT;
use const TCP_KEEPIDLE;
use const TCP_KEEPINTVL;
use const TCP_USER_TIMEOUT;

#[CoversClass(KeepAliveSocketConnector::class)]
final class KeepAliveSocketConnectorTest extends TestCase
{
    #[Test]
    #[RequiresOperatingSystemFamily('Linux')]
    public function resolvesDnsAndRetriesFailedAddressesWhilePreferringIpv4(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);
        $port = InternetAddress::fromString($address)->getPort();
        $resolver = $this->createMock(DnsResolver::class);
        $resolver->expects(self::once())->method('resolve')->with('uplink.test', null)->willReturn([
            new DnsRecord('::1', DnsRecord::AAAA),
            new DnsRecord('127.77.3.1', DnsRecord::A),
            new DnsRecord('127.0.0.1', DnsRecord::A),
        ]);

        try {
            $socket = new KeepAliveSocketConnector(dnsResolver: $resolver)->connect('uplink.test:' . $port);

            try {
                self::assertSame($address, $socket->getRemoteAddress()->toString());
            } finally {
                $socket->close();
            }
        } finally {
            fclose($server);
        }
    }

    #[Test]
    #[RequiresPhpExtension('sockets')]
    #[RequiresOperatingSystemFamily('Linux')]
    public function enablesTcpKeepaliveWithTheConfiguredTimers(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);

        try {
            $address = stream_socket_get_name($server, false);
            self::assertIsString($address);
            $socket = new KeepAliveSocketConnector(45, 7, 4)->connect('tcp://' . $address);

            try {
                $peer = stream_socket_accept($server, 1);
                self::assertIsResource($peer);
                fclose($peer);
                self::assertInstanceOf(ResourceSocket::class, $socket);
                $resource = $socket->getResource();
                self::assertIsResource($resource);
                $raw = socket_import_stream($resource);
                self::assertNotFalse($raw);
                self::assertSame(1, socket_get_option($raw, SOL_SOCKET, SO_KEEPALIVE));
                self::assertSame(45, socket_get_option($raw, SOL_TCP, TCP_KEEPIDLE));
                self::assertSame(7, socket_get_option($raw, SOL_TCP, TCP_KEEPINTVL));
                self::assertSame(4, socket_get_option($raw, SOL_TCP, TCP_KEEPCNT));
                self::assertSame(73_000, socket_get_option($raw, SOL_TCP, TCP_USER_TIMEOUT));
            } finally {
                $socket->close();
            }
        } finally {
            fclose($server);
        }
    }

    #[Test]
    public function warnsWhenTheUserTimeoutCannotBeSetWithoutTheSocketsExtension(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'TCP_USER_TIMEOUT is not set because ext-sockets or the operating system does not support it; a lost link with unacknowledged data is detected later.',
        );

        try {
            $address = stream_socket_get_name($server, false);
            self::assertIsString($address);
            $socket = new KeepAliveSocketConnector(logger: $logger, socketsAvailable: false)->connect('tcp://' . $address);
            $socket->close();
        } finally {
            fclose($server);
        }
    }

    #[Test]
    #[RequiresOperatingSystemFamily('Linux')]
    public function separatesPreviousAttemptsInTheFailureMessage(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);
        fclose($server);
        $port = InternetAddress::fromString($address)->getPort();
        $resolver = $this->createStub(DnsResolver::class);
        $resolver->method('resolve')->willReturn([
            new DnsRecord('127.0.0.1', DnsRecord::A),
            new DnsRecord('127.0.0.2', DnsRecord::A),
            new DnsRecord('127.0.0.3', DnsRecord::A),
        ]);
        $uri = 'uplink.test:' . $port;

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage(sprintf(
            'Connection to %1$s @ tcp://127.0.0.3:%2$d refused; previous attempts: %1$s @ tcp://127.0.0.1:%2$d (connection refused), %1$s @ tcp://127.0.0.2:%2$d (connection refused)',
            $uri,
            $port,
        ));

        new KeepAliveSocketConnector(dnsResolver: $resolver)->connect('uplink.test:' . $port);
    }

    /** @return iterable<string, array{int, int, int, string, int}> */
    public static function invalidTimers(): iterable
    {
        yield 'zero idle' => [0, 10, 6, 'idleSeconds', 32_767];
        yield 'idle above the kernel limit' => [32_768, 10, 6, 'idleSeconds', 32_767];
        yield 'negative interval' => [60, -1, 6, 'intervalSeconds', 32_767];
        yield 'interval above the kernel limit' => [60, 32_768, 6, 'intervalSeconds', 32_767];
        yield 'zero probes' => [60, 10, 0, 'probeCount', 127];
        yield 'probes above the kernel limit' => [60, 10, 128, 'probeCount', 127];
    }

    #[Test]
    #[DataProvider('invalidTimers')]
    public function rejectsKeepaliveTimersOutsideTheKernelLimits(int $idle, int $interval, int $probes, string $name, int $max): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Keepalive %s must be between 1 and %d.', $name, $max));

        new KeepAliveSocketConnector($idle, $interval, $probes);
    }

    #[Test]
    public function reportsAConnectFailureWhenDnsReturnsNoRecords(): void
    {
        $resolver = $this->createMock(DnsResolver::class);
        $resolver->expects(self::once())->method('resolve')->with('uplink.test', null)->willReturn([]);

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('DNS resolution for uplink.test returned no addresses');

        new KeepAliveSocketConnector(dnsResolver: $resolver)->connect('uplink.test:7000');
    }

    #[Test]
    public function wrapsDnsFailuresAndRespectsExplicitDnsType(): void
    {
        $reason = new DnsException('No answer');
        $resolver = $this->createMock(DnsResolver::class);
        $resolver->expects(self::once())->method('resolve')->with('uplink.test', DnsRecord::AAAA)->willThrowException($reason);

        try {
            new KeepAliveSocketConnector(dnsResolver: $resolver)->connect('uplink.test:7000', new ConnectContext()->withDnsTypeRestriction(DnsRecord::AAAA));
            self::fail('Expected a DNS failure.');
        } catch (ConnectException $error) {
            self::assertSame('DNS resolution for uplink.test failed: No answer', $error->getMessage());
            self::assertSame($reason, $error->getPrevious());
        }
    }

    #[Test]
    public function restrictsDnsToIpv6WhenBoundToAnIpv6Address(): void
    {
        $resolver = $this->createMock(DnsResolver::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with('uplink.test', DnsRecord::AAAA)
            ->willThrowException(new DnsException('No AAAA record'));

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('DNS resolution for uplink.test failed: No AAAA record');

        new KeepAliveSocketConnector(dnsResolver: $resolver)->connect('uplink.test:7000', new ConnectContext()->withBindTo('[::1]:0'));
    }

    #[Test]
    public function formatsImmediateStreamErrorsAndRestoresThePreviousHandler(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ares-socket-');
        self::assertIsString($path);
        unlink($path);

        $previous = static fn (): bool => true;
        set_error_handler($previous);
        try {
            try {
                new KeepAliveSocketConnector()->connect(new UnixAddress($path));
                self::fail('Expected the stream to fail immediately.');
            } catch (ConnectException $error) {
                self::assertStringStartsWith('Connection to unix://' . $path . ' failed:', $error->getMessage());
            }

            $active = set_error_handler($previous);
            restore_error_handler();
            self::assertSame($previous, $active);
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    #[RequiresOperatingSystemFamily('Linux')]
    public function splitsTheConnectTimeoutSoAnUnresponsiveAddressFallsBackInTime(): void
    {
        // A backlog of zero on Linux queues one connection. The next connect to it never completes.
        $stalled = stream_socket_server('tcp://127.0.0.1:0', context: stream_context_create(['socket' => ['backlog' => 0]]));
        self::assertIsResource($stalled);
        $address = stream_socket_get_name($stalled, false);
        self::assertIsString($address);
        $port = InternetAddress::fromString($address)->getPort();
        $listening = stream_socket_server('tcp://127.0.0.2:' . $port);
        self::assertIsResource($listening);
        $first = stream_socket_client('tcp://' . $address);
        self::assertIsResource($first);
        $resolver = $this->createStub(DnsResolver::class);
        $resolver->method('resolve')->willReturn([
            new DnsRecord('127.0.0.1', DnsRecord::A),
            new DnsRecord('127.0.0.2', DnsRecord::A),
        ]);

        try {
            $socket = new KeepAliveSocketConnector(dnsResolver: $resolver)->connect(
                'uplink.test:' . $port,
                new ConnectContext()->withConnectTimeout(0.4),
                new TimeoutCancellation(0.4),
            );

            try {
                self::assertSame('127.0.0.2:' . $port, $socket->getRemoteAddress()->toString());
            } finally {
                $socket->close();
            }
        } finally {
            fclose($first);
            fclose($listening);
            fclose($stalled);
        }
    }

    #[Test]
    #[RequiresOperatingSystemFamily('Linux')]
    public function distinguishesItsOwnDeadlineFromCallerCancellation(): void
    {
        // A backlog of zero on Linux queues one connection. The next connect waits for a slot.
        $server = stream_socket_server('tcp://127.0.0.1:0', context: stream_context_create(['socket' => ['backlog' => 0]]));
        self::assertIsResource($server);

        try {
            $address = stream_socket_get_name($server, false);
            self::assertIsString($address);
            $first = stream_socket_client('tcp://' . $address);
            self::assertIsResource($first);
            $connector = new KeepAliveSocketConnector();
            $context = new ConnectContext()->withConnectTimeout(0.025);

            try {
                try {
                    $connector->connect(InternetAddress::fromString($address), $context);
                    self::fail('Expected the connect deadline to expire.');
                } catch (ConnectException $error) {
                    $uri = 'tcp://' . $address;
                    self::assertSame(sprintf('Connecting to %s @ %s failed: timeout exceeded (0.025 s)', $uri, $uri), $error->getMessage());
                }

                $source = new DeferredCancellation();
                $source->cancel();
                $this->expectException(CancelledException::class);
                $connector->connect($address, $context, $source->getCancellation());
            } finally {
                fclose($first);
            }
        } finally {
            fclose($server);
        }
    }

    #[Test]
    #[RequiresOperatingSystemFamily('Linux')]
    public function keepsEarlierAddressFailuresWhenTheCallerDeadlineEndsTheLastAttempt(): void
    {
        [$port, $resources] = self::stalledListeners(['127.0.0.1', '127.0.0.2']);
        $resolver = $this->createStub(DnsResolver::class);
        $resolver->method('resolve')->willReturn([
            new DnsRecord('127.0.0.1', DnsRecord::A),
            new DnsRecord('127.0.0.2', DnsRecord::A),
        ]);

        try {
            new KeepAliveSocketConnector(dnsResolver: $resolver)->connect(
                'uplink.test:' . $port,
                new ConnectContext()->withConnectTimeout(0.4),
                new TimeoutCancellation(0.3),
            );
            self::fail('Expected the caller deadline to end the connect.');
        } catch (CancelledException $error) {
            $reason = $error->getPrevious();
            self::assertInstanceOf(ConnectException::class, $reason);
            self::assertStringStartsWith(sprintf(
                'Connection to uplink.test:%1$d was cancelled; attempted addresses: uplink.test:%1$d @ tcp://127.0.0.1:%1$d (connection timeout)',
                $port,
            ), $reason->getMessage());
            self::assertInstanceOf(CancelledException::class, $reason->getPrevious());
        } finally {
            foreach ($resources as $resource) {
                fclose($resource);
            }
        }
    }

    #[Test]
    #[RequiresOperatingSystemFamily('Linux')]
    public function givesTheRemainingDeadlineToTheNextAddress(): void
    {
        [$port, $resources] = self::stalledListeners(['127.0.0.1']);
        $resolver = $this->createStub(DnsResolver::class);
        // Nothing listens on 127.0.0.3, so the first address is refused at once.
        $resolver->method('resolve')->willReturn([
            new DnsRecord('127.0.0.3', DnsRecord::A),
            new DnsRecord('127.0.0.1', DnsRecord::A),
        ]);

        try {
            new KeepAliveSocketConnector(dnsResolver: $resolver)->connect(
                'uplink.test:' . $port,
                new ConnectContext()->withConnectTimeout(0.4),
                new TimeoutCancellation(2),
            );
            self::fail('Expected the stalled address to time out.');
        } catch (ConnectException $error) {
            self::assertMatchesRegularExpression('/@ tcp:\/\/127\.0\.0\.1:\d+ failed: timeout exceeded \(0\.3\d\d s\)/', $error->getMessage());
        } finally {
            foreach ($resources as $resource) {
                fclose($resource);
            }
        }
    }

    #[Test]
    public function reportsOneFailureWhenDnsUsesUpTheConnectTimeout(): void
    {
        $resolver = $this->createStub(DnsResolver::class);
        // This resolver ignores the cancellation and answers after the connect timeout.
        $resolver->method('resolve')->willReturnCallback(static function (): array {
            delay(0.1);

            return [new DnsRecord('127.0.0.1', DnsRecord::A), new DnsRecord('127.0.0.2', DnsRecord::A)];
        });

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('DNS resolution for uplink.test used up the connect timeout of 0.050 s');

        new KeepAliveSocketConnector(dnsResolver: $resolver)->connect('uplink.test:7000', new ConnectContext()->withConnectTimeout(0.05));
    }

    #[Test]
    public function stopsDnsResolutionAtTheConnectTimeout(): void
    {
        $resolver = $this->createStub(DnsResolver::class);
        $resolver->method('resolve')->willReturnCallback(static function (string $name, ?int $type, Cancellation $cancellation): never {
            delay(5, cancellation: $cancellation);

            throw new DnsException('Not reached');
        });

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('DNS resolution for uplink.test timed out after 0.050 s');

        new KeepAliveSocketConnector(dnsResolver: $resolver)->connect('uplink.test:7000', new ConnectContext()->withConnectTimeout(0.05));
    }

    #[Test]
    public function rethrowsTheCallerCancellationDuringDnsResolution(): void
    {
        $source = new DeferredCancellation();
        $source->cancel();
        $resolver = $this->createStub(DnsResolver::class);
        $resolver->method('resolve')->willReturnCallback(static function (string $name, ?int $type, Cancellation $cancellation): never {
            $cancellation->throwIfRequested();

            throw new DnsException('Not reached');
        });

        $this->expectException(CancelledException::class);

        new KeepAliveSocketConnector(dnsResolver: $resolver)->connect('uplink.test:7000', cancellation: $source->getCancellation());
    }

    #[Test]
    public function passesTheCallerCancellationToTheDnsResolver(): void
    {
        $source = new DeferredCancellation();
        $cancellation = $source->getCancellation();
        $resolver = $this->createMock(DnsResolver::class);
        $resolver->expects(self::once())->method('resolve')
            ->with('uplink.test', null, self::isInstanceOf(Cancellation::class))
            ->willReturnCallback(static function (string $name, ?int $type, Cancellation $passed) use ($source): never {
                // The resolver sees the caller cancellation through the combined one.
                $source->cancel();
                $passed->throwIfRequested();

                throw new DnsException('Not reached');
            });

        $this->expectException(CancelledException::class);

        new KeepAliveSocketConnector(dnsResolver: $resolver)->connect('uplink.test:7000', cancellation: $cancellation);
    }

    #[Test]
    public function rejectsAKeepaliveWindowTooLongForTcpUserTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The keepalive window (idleSeconds + intervalSeconds * probeCount) must be at most 2147483 seconds.');

        new KeepAliveSocketConnector(32_767, 32_767, 65);
    }

    #[Test]
    #[RequiresPhpExtension('sockets')]
    #[RequiresOperatingSystemFamily('Linux')]
    public function appliesTheLongestKeepaliveWindowTcpUserTimeoutAllows(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        try {
            $address = stream_socket_get_name($server, false);
            self::assertIsString($address);
            $socket = new KeepAliveSocketConnector(32_767, 32_767, 64, logger: $logger)->connect('tcp://' . $address);

            try {
                self::assertInstanceOf(ResourceSocket::class, $socket);
                $resource = $socket->getResource();
                self::assertIsResource($resource);
                $raw = socket_import_stream($resource);
                self::assertNotFalse($raw);
                self::assertSame(2_129_855_000, socket_get_option($raw, SOL_TCP, TCP_USER_TIMEOUT));
            } finally {
                $socket->close();
            }
        } finally {
            fclose($server);
        }
    }

    /**
     * Opens listeners with a full backlog of zero, so a new connect to them never completes.
     *
     * @param list<string> $hosts
     *
     * @return array{int, list<resource>}
     */
    private static function stalledListeners(array $hosts): array
    {
        $port = 0;
        $resources = [];
        foreach ($hosts as $host) {
            $server = stream_socket_server(sprintf('tcp://%s:%d', $host, $port), context: stream_context_create(['socket' => ['backlog' => 0]]));
            self::assertIsResource($server);
            $address = stream_socket_get_name($server, false);
            self::assertIsString($address);
            $port = InternetAddress::fromString($address)->getPort();
            $queued = stream_socket_client('tcp://' . $address);
            self::assertIsResource($queued);
            $resources[] = $server;
            $resources[] = $queued;
        }

        return [$port, $resources];
    }
}
