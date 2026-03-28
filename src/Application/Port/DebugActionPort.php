<?php

declare(strict_types=1);

namespace App\Application\Port;

interface DebugActionPort
{
    public function isConfigured(): bool;

    public function ensureChannelJoined(): void;

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
