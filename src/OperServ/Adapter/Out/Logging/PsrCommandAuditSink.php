<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Logging;

use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\Out\CommandAuditSink;
use Psr\Log\LoggerInterface;

use const DATE_ATOM;

final readonly class PsrCommandAuditSink implements CommandAuditSink
{
    public function __construct(private LoggerInterface $logger) {}

    public function record(CommandAuditRecord $record): void
    {
        $context = [
            'category' => $record->category->value,
            'service' => $record->service,
            'actor' => $record->actor,
            'operation' => $record->operation,
            'occurred_at' => $record->occurredAt->format(DATE_ATOM),
        ];

        if (null !== $record->target) {
            $context['target'] = $record->target;
        }

        if (null !== $record->permission) {
            $context['permission'] = $record->permission;
        }

        if (null !== $record->targetHost) {
            $context['target_host'] = $record->targetHost;
        }

        if (null !== $record->targetIp) {
            $context['target_ip'] = $record->targetIp;
        }

        if (null !== $record->reason) {
            $context['reason'] = $record->reason;
        }

        foreach ($record->metadata as $key => $value) {
            $context['metadata_' . $key] = $value;
        }

        $this->logger->info('Security command audit', $context);
    }
}
