<?php

declare(strict_types=1);

namespace App\Application\Port;

interface ServiceDebugNotifierInterface
{
    public function getServiceName(): string;

    public function isConfigured(): bool;

    public function ensureChannelJoined(): void;

    /**
     * Log an IRCop command execution for audit purposes.
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
