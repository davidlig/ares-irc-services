<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;

interface CommandAuditSink
{
    public function record(CommandAuditRecord $record): void;
}
