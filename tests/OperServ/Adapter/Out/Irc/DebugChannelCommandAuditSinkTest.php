<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Irc;

use App\OperServ\Adapter\Out\Irc\DebugChannelCommandAuditSink;
use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\Out\CommandAuditIrcPresenter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DebugChannelCommandAuditSink::class)]
final class DebugChannelCommandAuditSinkTest extends TestCase
{
    #[Test]
    public function delegatesOnlySemanticPresentationToTheIrcPort(): void
    {
        $record = new CommandAuditRecord(
            CommandAuditCategory::ResourceOverride,
            'chanserv',
            'Oper',
            'chanserv.level_founder.set',
            new DateTimeImmutable('2026-09-08T10:15:00+00:00'),
            '#channel',
        );

        $presenter = $this->createMock(CommandAuditIrcPresenter::class);
        $presenter->expects(self::once())->method('present')->with(self::identicalTo($record));

        new DebugChannelCommandAuditSink($presenter)->record($record);
    }
}
