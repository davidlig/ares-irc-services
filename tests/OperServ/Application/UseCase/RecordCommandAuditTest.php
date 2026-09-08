<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Application\UseCase;

use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\Out\CommandAuditSink;
use App\OperServ\Application\UseCase\RecordCommandAudit;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordCommandAudit::class)]
final class RecordCommandAuditTest extends TestCase
{
    #[Test]
    public function sendsTheSameRecordToEverySink(): void
    {
        $record = $this->record();
        $first = $this->createMock(CommandAuditSink::class);
        $first->expects(self::once())->method('record')->with(self::identicalTo($record));
        $second = $this->createMock(CommandAuditSink::class);
        $second->expects(self::once())->method('record')->with(self::identicalTo($record));

        $useCase = new RecordCommandAudit((static function () use ($first, $second): iterable {
            yield $first;
            yield $second;
        })());

        $useCase->record($record);
    }

    #[Test]
    public function acceptsAnEmptySinkCollection(): void
    {
        self::expectNotToPerformAssertions();

        new RecordCommandAudit([])->record($this->record());
    }

    private function record(): CommandAuditRecord
    {
        return new CommandAuditRecord(
            CommandAuditCategory::SystemAction,
            'operserv',
            'system',
            'operserv.gline.expire',
            new DateTimeImmutable('2026-09-08T10:15:00+00:00'),
        );
    }
}
