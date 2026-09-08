<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Irc;

use App\Application\Port\ServiceDebugNotifierRegistry;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\Out\CommandAuditIrcPresenter;

final readonly class LegacyDebugChannelCommandAuditPresenter implements CommandAuditIrcPresenter
{
    public function __construct(private ServiceDebugNotifierRegistry $notifiers) {}

    public function present(CommandAuditRecord $record): void
    {
        $notifier = $this->notifiers->get($record->service);
        if (null === $notifier || !$notifier->isConfigured()) {
            return;
        }

        $notifier->ensureChannelJoined();
        $notifier->log(
            operator: $record->actor,
            command: $record->operation,
            target: $record->target ?? '',
            targetHost: $record->targetHost,
            targetIp: $record->targetIp,
            reason: $record->reason,
            extra: $record->metadata,
        );
    }
}
