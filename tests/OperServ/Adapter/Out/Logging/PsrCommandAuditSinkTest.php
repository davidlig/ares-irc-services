<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Logging;

use App\OperServ\Adapter\Out\Logging\PsrCommandAuditSink;
use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(PsrCommandAuditSink::class)]
final class PsrCommandAuditSinkTest extends TestCase
{
    #[Test]
    public function writesTheSemanticRecordAsBoundedLogContext(): void
    {
        $record = new CommandAuditRecord(
            category: CommandAuditCategory::RootAdministration,
            service: 'operserv',
            actor: 'Root',
            operation: 'operserv.role.permission.add',
            occurredAt: new DateTimeImmutable('2026-09-08T10:15:00+02:00'),
            permission: 'ROOT',
            target: 'NETWORK-ADMIN',
            targetHost: 'root@example.test',
            targetIp: '192.0.2.10',
            reason: 'approved change',
            metadata: ['permission' => 'operserv.kill', 'protected' => true],
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('Security command audit', [
                'category' => 'root_administration',
                'service' => 'operserv',
                'actor' => 'Root',
                'operation' => 'operserv.role.permission.add',
                'occurred_at' => '2026-09-08T10:15:00+02:00',
                'target' => 'NETWORK-ADMIN',
                'permission' => 'ROOT',
                'target_host' => 'root@example.test',
                'target_ip' => '192.0.2.10',
                'reason' => 'approved change',
                'metadata_permission' => 'operserv.kill',
                'metadata_protected' => true,
            ]);

        new PsrCommandAuditSink($logger)->record($record);
    }

    #[Test]
    public function omitsAbsentOptionalData(): void
    {
        $record = new CommandAuditRecord(
            CommandAuditCategory::SystemAction,
            'operserv',
            'system',
            'operserv.motd.expire',
            new DateTimeImmutable('2026-09-08T08:15:00+00:00'),
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('Security command audit', [
                'category' => 'system_action',
                'service' => 'operserv',
                'actor' => 'system',
                'operation' => 'operserv.motd.expire',
                'occurred_at' => '2026-09-08T08:15:00+00:00',
            ]);

        new PsrCommandAuditSink($logger)->record($record);
    }
}
