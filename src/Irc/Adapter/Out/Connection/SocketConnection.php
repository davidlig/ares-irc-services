<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Out\Connection;

use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket;
use Amp\TimeoutCancellation;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

use function Amp\Socket\connect;
use function Amp\Socket\connectTls;
use function rtrim;
use function sprintf;
use function strpos;
use function substr;

class SocketConnection implements ConnectionInterface
{
    private ConnectionStatus $status = ConnectionStatus::Disconnected;

    private ?Socket $socket = null;

    private string $recvBuffer = '';

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly bool $useTls = false,
        private readonly int $timeoutSeconds = 30,
        private readonly bool $tlsVerifyPeer = true,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function connect(): void
    {
        $uri = sprintf('%s:%d', $this->host, $this->port);

        $this->logger->info('Opening TCP connection.', [
            'address' => $uri,
            'timeout' => $this->timeoutSeconds,
            'tls' => $this->useTls,
        ]);

        $this->status = ConnectionStatus::Connecting;

        $connectContext = new ConnectContext()
            ->withConnectTimeout((float) $this->timeoutSeconds);

        if ($this->useTls) {
            $tlsContext = new ClientTlsContext($this->host);
            if (!$this->tlsVerifyPeer) {
                $tlsContext = $tlsContext->withoutPeerVerification();
                $this->logger->warning('TLS peer verification is disabled for IRC connection.', [
                    'host' => $this->host,
                ]);
            }

            $connectContext = $connectContext->withTlsContext($tlsContext);
        }

        $cancellation = new TimeoutCancellation((float) $this->timeoutSeconds);

        try {
            $this->socket = $this->useTls
                ? connectTls($uri, $connectContext, $cancellation)
                : connect($uri, $connectContext, $cancellation);

            $this->status = ConnectionStatus::Connected;
            $this->recvBuffer = '';

            $this->logger->info('TCP connection established.', ['address' => $uri]);
        } catch (Throwable $e) {
            $this->status = ConnectionStatus::Error;

            $this->logger->error('TCP connection failed.', [
                'address' => $uri,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(sprintf('Failed to connect to %s: %s', $uri, $e->getMessage()), 0, $e);
        }
    }

    public function disconnect(): void
    {
        if (null !== $this->socket) {
            $this->socket->close();
            $this->socket = null;
        }

        $this->status = ConnectionStatus::Disconnected;
        $this->recvBuffer = '';
        $this->logger->info('TCP connection closed.', [
            'host' => $this->host,
            'port' => $this->port,
        ]);
    }

    public function writeLine(string $data): void
    {
        if (!$this->isConnected()) {
            throw new RuntimeException('Cannot write: connection is not open.');
        }

        $payload = $data . "\r\n";

        try {
            $this->socket?->write($payload);
        } catch (Throwable $e) {
            $this->status = ConnectionStatus::Error;

            $this->logger->error('Failed to write to the IRC connection.', [
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to write to the IRC connection.', 0, $e);
        }
    }

    public function readLine(): ?string
    {
        if (!$this->isConnected()) {
            return null;
        }

        $newlinePos = strpos($this->recvBuffer, "\n");

        while (false === $newlinePos) {
            try {
                $chunk = $this->socket?->read();
            } catch (Throwable $e) {
                $this->status = ConnectionStatus::Error;

                $this->logger->error('Error reading from IRC connection.', [
                    'error' => $e->getMessage(),
                ]);

                throw new RuntimeException(sprintf('Error reading from IRC connection: %s', $e->getMessage()), 0, $e);
            }

            if (null === $chunk) {
                break;
            }

            $this->recvBuffer .= $chunk;
            $newlinePos = strpos($this->recvBuffer, "\n");
        }

        if (false !== $newlinePos) {
            $line = substr($this->recvBuffer, 0, $newlinePos);
            $this->recvBuffer = substr($this->recvBuffer, $newlinePos + 1);

            return rtrim($line, "\r");
        }

        if ('' !== $this->recvBuffer) {
            $line = $this->recvBuffer;
            $this->recvBuffer = '';

            return rtrim($line, "\r");
        }

        $this->status = ConnectionStatus::Disconnected;

        return null;
    }

    public function isConnected(): bool
    {
        if (ConnectionStatus::Connected !== $this->status || null === $this->socket || $this->socket->isClosed()) {
            return false;
        }

        return $this->socket->isReadable() || '' !== $this->recvBuffer;
    }

    public function getStatus(): ConnectionStatus
    {
        return $this->status;
    }
}
