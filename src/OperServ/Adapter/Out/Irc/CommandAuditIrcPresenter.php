<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Irc;

use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;

/** Presents one safe semantic audit record through the configured IRC output. */
interface CommandAuditIrcPresenter
{
    public function present(CommandAuditRecord $record): void;
}
