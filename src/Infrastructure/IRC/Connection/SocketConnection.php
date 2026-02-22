<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Connection;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Connection\ConnectionStatus;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class SocketConnection implements ConnectionInterface
{
    private ConnectionStatus $status = ConnectionStatus::Disconnected;

    /** @var resource|null */
    private mixed $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly bool $useTls = false,
        private readonly int $timeoutSeconds = 30,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function connect(): void
    {
        $scheme  = $this->useTls ? 'tls' : 'tcp';
        $address = sprintf('%s://%s:%d', $scheme, $this->host, $this->port);

        $this->logger->info('Opening TCP connection.', [
            'address' => $address,
            'timeout' => $this->timeoutSeconds,
        ]);

        $this->status = ConnectionStatus::Connecting;

        $errorCode    = 0;
        $errorMessage = '';

        $socket = stream_socket_client(
            address: $address,
            error_code: $errorCode,
            error_message: $errorMessage,
            timeout: $this->timeoutSeconds,
            flags: STREAM_CLIENT_CONNECT,
            context: stream_context_create(),
        );

        if (false === $socket) {
            $this->status = ConnectionStatus::Error;

            $this->logger->error('TCP connection failed.', [
                'address' => $address,
                'code'    => $errorCode,
                'error'   => $errorMessage,
            ]);

            throw new \RuntimeException(
                sprintf('Failed to connect to %s: [%d] %s', $address, $errorCode, $errorMessage)
            );
        }

        stream_set_blocking($socket, false);

        $this->socket = $socket;
        $this->status = ConnectionStatus::Connected;

        $this->logger->info('TCP connection established.', ['address' => $address]);
    }

    public function disconnect(): void
    {
        if (null !== $this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }

        $this->status = ConnectionStatus::Disconnected;
        $this->logger->info('TCP connection closed.', [
            'host' => $this->host,
            'port' => $this->port,
        ]);
    }

    public function writeLine(string $data): void
    {
        if (!$this->isConnected()) {
            throw new \RuntimeException('Cannot write: connection is not open.');
        }

        fwrite($this->socket, $data . "\r\n");
    }

    public function readLine(): ?string
    {
        if (!$this->isConnected()) {
            return null;
        }

        $line = fgets($this->socket);

        if (false === $line) {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    public function isConnected(): bool
    {
        return null !== $this->socket && !feof($this->socket);
    }

    public function getStatus(): ConnectionStatus
    {
        return $this->status;
    }
}
