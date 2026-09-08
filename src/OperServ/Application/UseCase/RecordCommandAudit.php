<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase;

use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\Out\CommandAuditSink;

final readonly class RecordCommandAudit implements CommandAuditRecorder
{
    /** @var list<CommandAuditSink> */
    private array $sinks;

    /** @param iterable<CommandAuditSink> $sinks */
    public function __construct(iterable $sinks)
    {
        $resolvedSinks = [];
        foreach ($sinks as $sink) {
            $resolvedSinks[] = $sink;
        }

        $this->sinks = $resolvedSinks;
    }

    public function record(CommandAuditRecord $record): void
    {
        foreach ($this->sinks as $sink) {
            $sink->record($record);
        }
    }
}
