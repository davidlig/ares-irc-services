<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Out\Connection;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Amp\Dns\DnsResolver;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\InternetAddress;
use Amp\Socket\UnixAddress;
use App\Irc\Adapter\Out\Connection\KeepAliveSocketConnector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function stream_context_create;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_server;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(KeepAliveSocketConnector::class)]
final class KeepAliveSocketConnectorTest extends TestCase
{
    #[Test]
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
}
