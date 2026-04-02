<?php

declare(strict_types=1);

namespace App\Application\Port;

interface DebugActionPort
{
    public function isConfigured(): bool;

    public function ensureChannelJoined(): void;

    /**
     * @param string      $operator   Operator nickname
     * @param string      $command    Command name (e.g., 'KILL', 'GLINE ADD')
     * @param string      $target     Target of the command
     * @param string|null $targetHost Target host (ident@hostname)
     * @param string|null $targetIp   Target IP
     * @param string|null $reason     Reason or message content
     * @param array       $extra      Extra parameters (e.g., ['duration' => '1h', 'reasonType' => 'message'])
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
