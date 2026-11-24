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
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

use function Amp\Dns\dnsResolver;
use function Amp\Socket\Internal\parseUri;
use function assert;
use function count;
use function defined;
use function extension_loaded;
use function fclose;
use function hrtime;
use function implode;
use function inet_pton;
use function max;
use function restore_error_handler;
use function set_error_handler;
use function socket_import_stream;
use function socket_last_error;
use function socket_set_option;
use function socket_strerror;
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
use const SOL_TCP;
use const TCP_USER_TIMEOUT;

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
    private bool $socketsAvailable;

    /**
     * @param ?bool $socketsAvailable overrides the ext-sockets detection, for tests
     */
    public function __construct(
        private int $idleSeconds = 60,
        private int $intervalSeconds = 10,
        private int $probeCount = 6,
        private ?DnsResolver $dnsResolver = null,
        private ?LoggerInterface $logger = null,
        ?bool $socketsAvailable = null,
    ) {
        // Linux ignores values above these limits and keeps its own defaults without an error.
        $limits = [
            'idleSeconds' => [$idleSeconds, 32_767],
            'intervalSeconds' => [$intervalSeconds, 32_767],
            'probeCount' => [$probeCount, 127],
        ];
        foreach ($limits as $name => [$value, $max]) {
            if ($value < 1 || $value > $max) {
                throw new \InvalidArgumentException(sprintf('Keepalive %s must be between 1 and %d.', $name, $max));
            }
        }

        // TCP_USER_TIMEOUT is a C int of milliseconds; a longer window would always be rejected.
        if ($idleSeconds + $intervalSeconds * $probeCount > 2_147_483) {
            throw new \InvalidArgumentException('The keepalive window (idleSeconds + intervalSeconds * probeCount) must be at most 2147483 seconds.');
        }

        // SOL_TCP and TCP_USER_TIMEOUT exist only where the operating system defines them.
        $this->socketsAvailable = $socketsAvailable
            ?? (extension_loaded('sockets') && defined('SOL_TCP') && defined('TCP_USER_TIMEOUT'));
    }

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
        $deadline = hrtime(true) / 1e9 + $context->getConnectTimeout();
        $targets = $this->resolve($uri, $scheme, trim($host, '[]'), $port, $context, $cancellation);
        if ([$uri] !== $targets && hrtime(true) / 1e9 >= $deadline) {
            throw new ConnectException(sprintf('DNS resolution for %s used up the connect timeout of %0.3f s', $host, $context->getConnectTimeout()), CONNECTION_TIMEOUT);
        }

        $failures = [];
        foreach ($targets as $index => $target) {
            // Each address gets an equal share of the time left, so an address that never
            // answers leaves time for the next one, and time a quick failure saves is reused.
            $timeout = max(0.0, $deadline - hrtime(true) / 1e9) / (count($targets) - $index);
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
                return $this->connectTo($uri, $scheme, $target, $context, $cancellation, $streamContext, $failures, $timeout);
            } catch (CancelledException $exception) {
                if ([] === $failures) {
                    throw $exception;
                }

                // Keep Amp's CancelledException contract and carry the address failures with it.
                throw new CancelledException(new ConnectException(sprintf('Connection to %s was cancelled; attempted addresses: %s', $uri, implode(', ', $failures)), previous: $exception));
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
        string $scheme,
        string $target,
        ConnectContext $context,
        Cancellation $cancellation,
        $streamContext,
        array $failures,
        float $timeout,
    ): Socket {
        $history = [] === $failures ? '' : '; previous attempts: ' . implode(', ', $failures);
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

            if ('tcp' === $scheme) {
                $this->setUserTimeout($socket);
            }

            return ResourceSocket::fromClientSocket($socket, $context->getTlsContext());
        } catch (\Throwable $exception) {
            fclose($socket);

            throw $exception;
        }
    }

    /**
     * Keepalive probes stop while sent data is unacknowledged. This timeout closes the
     * link after the same window when the peer stops acknowledging data.
     *
     * @param resource $socket
     */
    private function setUserTimeout($socket): void
    {
        if (!$this->socketsAvailable) {
            $this->logger?->warning('TCP_USER_TIMEOUT is not set because ext-sockets or the operating system does not support it; a lost link with unacknowledged data is detected later.');

            return;
        }

        // Suppress warnings so a strict error handler cannot fail a connected link here.
        $raw = @socket_import_stream($socket);
        $timeout = ($this->idleSeconds + $this->intervalSeconds * $this->probeCount) * 1000;
        if (false === $raw || !@socket_set_option($raw, SOL_TCP, TCP_USER_TIMEOUT, $timeout)) {
            // The constructor keeps the window in range, so only an unusual kernel or stream gets here.
            // @codeCoverageIgnoreStart
            $reason = false === $raw ? 'the stream cannot be imported' : socket_strerror(socket_last_error($raw));
            $this->logger?->warning(sprintf('TCP_USER_TIMEOUT is not set: %s; a lost link with unacknowledged data is detected later.', $reason));
            // @codeCoverageIgnoreEnd
        }
    }

    /** @return non-empty-list<string> */
    private function resolve(string $uri, string $scheme, string $host, int $port, ConnectContext $context, Cancellation $cancellation): array
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

        // The connect timeout also bounds DNS, even when the caller passes no cancellation.
        $timeout = $context->getConnectTimeout();

        try {
            $records = ($this->dnsResolver ?? dnsResolver())->resolve($host, $restriction, new CompositeCancellation($cancellation, new TimeoutCancellation($timeout)));
        } catch (CancelledException $exception) {
            $cancellation->throwIfRequested();

            throw new ConnectException(sprintf('DNS resolution for %s timed out after %0.3f s', $host, $timeout), CONNECTION_TIMEOUT, $exception);
        } catch (DnsException $exception) {
            throw new ConnectException(sprintf('DNS resolution for %s failed: %s', $host, $exception->getMessage()), previous: $exception);
        }

        // DnsResolver promises a non-empty list, but a custom resolver can still return none.
        // @phpstan-ignore identical.alwaysFalse
        if ([] === $records) {
            throw new ConnectException(sprintf('DNS resolution for %s returned no addresses', $host));
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
