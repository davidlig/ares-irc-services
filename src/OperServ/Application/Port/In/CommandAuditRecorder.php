<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\In;

use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;

interface CommandAuditRecorder
{
    public function record(CommandAuditRecord $record): void;
}
