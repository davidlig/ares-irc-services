<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

interface ServiceDebugNotifierInterface
{
    public function getServiceName(): string;

    public function isConfigured(): bool;

    public function ensureChannelJoined(): void;

    public function notify(string $message): void;

    /**
     * Present an already-sanitized command audit record to the IRC debug channel.
     *
     * @param array<string, mixed> $extra Additional data (option, value, duration, etc.)
     */
    public function log(
        string $operator,
        string $command,
        string $target,
        ?string $targetHost = null,
        ?string $targetIp = null,
        ?string $reason = null,
        array $extra = [],
    ): void;
}
