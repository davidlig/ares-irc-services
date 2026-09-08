<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Irc;

use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\Out\CommandAuditIrcPresenter;
use App\OperServ\Application\Port\Out\CommandAuditSink;

final readonly class DebugChannelCommandAuditSink implements CommandAuditSink
{
    public function __construct(private CommandAuditIrcPresenter $presenter) {}

    public function record(CommandAuditRecord $record): void
    {
        $this->presenter->present($record);
    }
}
