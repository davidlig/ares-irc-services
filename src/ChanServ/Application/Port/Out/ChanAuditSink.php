<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface ChanAuditSink
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
