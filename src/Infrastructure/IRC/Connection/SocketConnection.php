<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Connection;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Connection\ConnectionStatus;

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
    ) {
    }

    public function connect(): void
    {
        $this->status = ConnectionStatus::Connecting;

        $scheme = $this->useTls ? 'tls' : 'tcp';
        $address = sprintf('%s://%s:%d', $scheme, $this->host, $this->port);

        $errorCode = 0;
        $errorMessage = '';

        $socket = stream_socket_client(
            address: $address,
            error_code: $errorCode,
            error_message: $errorMessage,
            timeout: $this->timeoutSeconds,
            flags: STREAM_CLIENT_CONNECT,
            context: stream_context_create(),
        );

        if ($socket === false) {
            $this->status = ConnectionStatus::Error;
            throw new \RuntimeException(
                sprintf('Failed to connect to %s: [%d] %s', $address, $errorCode, $errorMessage)
            );
        }

        stream_set_blocking($socket, false);

        $this->socket = $socket;
        $this->status = ConnectionStatus::Connected;
    }

    public function disconnect(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }

        $this->status = ConnectionStatus::Disconnected;
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

        if ($line === false) {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    public function isConnected(): bool
    {
        return $this->socket !== null && !feof($this->socket);
    }

    public function getStatus(): ConnectionStatus
    {
        return $this->status;
    }
}
