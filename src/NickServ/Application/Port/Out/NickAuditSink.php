<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface NickAuditSink
{
    /**
     * @param array<string, mixed> $extra
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
