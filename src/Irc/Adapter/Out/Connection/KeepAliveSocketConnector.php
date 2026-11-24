<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Out\Connection;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredFuture;
use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Amp\Dns\DnsResolver;
use Amp\NullCancellation;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\ResourceSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketAddressType;
use Amp\Socket\SocketConnector;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;

use function Amp\Dns\dnsResolver;
use function Amp\Socket\Internal\parseUri;
use function assert;
use function fclose;
use function implode;
use function inet_pton;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function str_starts_with;
use function stream_context_create;
use function stream_context_set_options;
use function stream_set_blocking;
use function stream_socket_client;
use function stream_socket_get_name;
use function trim;
use function usort;

use const Amp\Socket\Internal\CONNECTION_BUSY;
use const Amp\Socket\Internal\CONNECTION_REFUSED;
use const Amp\Socket\Internal\CONNECTION_TIMEOUT;

/**
 * Amp's asynchronous DNS and stream connection flow with TCP keepalive options.
 *
 * Adapted from Amp\\Socket\\DnsSocketConnector (MIT, copyright 2015–2023 amphp).
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */
final readonly class KeepAliveSocketConnector implements SocketConnector
{
    public function __construct(
        private int $idleSeconds = 60,
        private int $intervalSeconds = 10,
        private int $probeCount = 6,
        private ?DnsResolver $dnsResolver = null,
    ) {}

    public function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket
    {
        $context ??= new ConnectContext();
        $cancellation ??= new NullCancellation();

        if ($uri instanceof SocketAddress) {
            $uri = match ($uri->getType()) {
                SocketAddressType::Internet => 'tcp://' . $uri->toString(),
                SocketAddressType::Unix => 'unix://' . $uri->toString(),
            };
        }

        /** @var array{string, string, int} $address */
        $address = parseUri($uri);
        [$scheme, $host, $port] = $address;
        $targets = $this->resolve($uri, $scheme, trim($host, '[]'), $port, $context);
        $failures = [];
        foreach ($targets as $target) {
            $streamContext = stream_context_create($context->withoutTlsContext()->toStreamContextArray());
            if ('tcp' === $scheme) {
                stream_context_set_options($streamContext, ['socket' => [
                    'so_keepalive' => true,
                    'tcp_keepidle' => $this->idleSeconds,
                    'tcp_keepintvl' => $this->intervalSeconds,
                    'tcp_keepcnt' => $this->probeCount,
                ]]);
            }

            try {
                return $this->connectTo($uri, $target, $context, $cancellation, $streamContext, $failures);
            } catch (ConnectException $exception) {
                $failure = $exception;
                $reasons = [
                    CONNECTION_BUSY => 'connection busy',
                    CONNECTION_TIMEOUT => 'connection timeout',
                    CONNECTION_REFUSED => 'connection refused',
                ];
                $reason = $reasons[$exception->getCode()] ?? ('Error #' . $exception->getCode());
                $failures[] = "$uri @ $target ($reason)";
            }
        }

        throw $failure;
    }

    /**
     * @param resource     $streamContext
     * @param list<string> $failures
     */
    private function connectTo(
        string $uri,
        string $target,
        ConnectContext $context,
        Cancellation $cancellation,
        $streamContext,
        array $failures,
    ): Socket {
        $history = [] === $failures ? '' : '; previous attempts: ' . implode($failures);
        set_error_handler(static function (int $code, string $message) use ($uri, $target, $history): never {
            $authority = $uri === $target ? $target : $uri . ' @ ' . $target;

            throw new ConnectException(sprintf('Connection to %s failed: (Error #%d) %s%s', $authority, $code, $message, $history), $code);
        });

        try {
            $socket = stream_socket_client($target, flags: STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, context: $streamContext);
        } finally {
            restore_error_handler();
        }

        // The stream raises a warning on failure, which the handler converts above.
        assert(false !== $socket);

        try {
            stream_set_blocking($socket, false);
            $ready = new DeferredFuture();
            $watcher = EventLoop::onWritable($socket, static function (string $watcher) use ($ready): void {
                EventLoop::cancel($watcher);
                $ready->complete();
            });

            $timeout = $context->getConnectTimeout();
            try {
                $ready->getFuture()->await(new CompositeCancellation($cancellation, new TimeoutCancellation($timeout)));
            } catch (CancelledException) {
                $cancellation->throwIfRequested();

                throw new ConnectException(sprintf('Connecting to %s @ %s failed: timeout exceeded (%0.3f s)%s', $uri, $target, $timeout, $history), CONNECTION_TIMEOUT);
            } finally {
                EventLoop::cancel($watcher);
            }

            if (false === stream_socket_get_name($socket, true)) {
                throw new ConnectException(sprintf('Connection to %s @ %s refused%s', $uri, $target, $history), CONNECTION_REFUSED);
            }

            return ResourceSocket::fromClientSocket($socket, $context->getTlsContext());
        } catch (\Throwable $exception) {
            fclose($socket);

            throw $exception;
        }
    }

    /** @return non-empty-list<string> */
    private function resolve(string $uri, string $scheme, string $host, int $port, ConnectContext $context): array
    {
        if (0 === $port || false !== inet_pton($host)) {
            return [$uri];
        }

        $bindTo = $context->getBindTo();
        $restriction = $context->getDnsTypeRestriction() ?? match (true) {
            null === $bindTo => null,
            str_starts_with($bindTo, '[') => DnsRecord::AAAA,
            default => DnsRecord::A,
        };

        try {
            $records = ($this->dnsResolver ?? dnsResolver())->resolve($host, $restriction);
        } catch (DnsException $exception) {
            throw new ConnectException(sprintf('DNS resolution for %s failed: %s', $host, $exception->getMessage()), previous: $exception);
        }

        // Match Amp's IPv4-first fallback order.
        usort($records, static fn (DnsRecord $a, DnsRecord $b): int => $a->getType() <=> $b->getType());

        $targets = [];
        foreach ($records as $record) {
            $address = $record->getValue();
            $targets[] = DnsRecord::AAAA === $record->getType()
                ? sprintf('%s://[%s]:%d', $scheme, $address, $port)
                : sprintf('%s://%s:%d', $scheme, $address, $port);
        }

        return $targets;
    }
}
