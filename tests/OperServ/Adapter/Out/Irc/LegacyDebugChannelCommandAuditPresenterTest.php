<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Irc;

use App\Application\Port\ServiceDebugNotifierInterface;
use App\Application\Port\ServiceDebugNotifierRegistry;
use App\OperServ\Adapter\Out\Irc\LegacyDebugChannelCommandAuditPresenter;
use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LegacyDebugChannelCommandAuditPresenter::class)]
final class LegacyDebugChannelCommandAuditPresenterTest extends TestCase
{
    #[Test]
    public function presentsOnlyToConfiguredServiceDebugChannel(): void
    {
        $notifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $notifier->method('getServiceName')->willReturn('operserv');
        $notifier->method('isConfigured')->willReturn(true);
        $notifier->expects(self::once())->method('ensureChannelJoined');
        $notifier->expects(self::once())->method('log')->with(
            'Root',
            'ROLE',
            'ADMIN',
            'root@example.test',
            '192.0.2.1',
            'approved',
            ['action' => 'PERMISSION_ADD'],
        );

        new LegacyDebugChannelCommandAuditPresenter(new ServiceDebugNotifierRegistry([$notifier]))->present(
            new CommandAuditRecord(
                category: CommandAuditCategory::RootAdministration,
                service: 'operserv',
                actor: 'Root',
                operation: 'ROLE',
                occurredAt: new DateTimeImmutable('2026-09-08T10:15:00+00:00'),
                target: 'ADMIN',
                reason: 'approved',
                permission: 'ROOT',
                targetHost: 'root@example.test',
                targetIp: '192.0.2.1',
                metadata: ['action' => 'PERMISSION_ADD'],
            ),
        );
    }

    #[Test]
    public function ignoresUnknownOrUnconfiguredServiceWithoutPresenting(): void
    {
        $notifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $notifier->method('getServiceName')->willReturn('operserv');
        $notifier->method('isConfigured')->willReturn(false);
        $notifier->expects(self::never())->method('ensureChannelJoined');
        $notifier->expects(self::never())->method('log');
        $presenter = new LegacyDebugChannelCommandAuditPresenter(new ServiceDebugNotifierRegistry([$notifier]));

        $presenter->present($this->record('operserv'));
        $presenter->present($this->record('missing'));
    }

    private function record(string $service): CommandAuditRecord
    {
        return new CommandAuditRecord(
            CommandAuditCategory::OperatorAction,
            $service,
            'Oper',
            'KILL',
            new DateTimeImmutable('2026-09-08T10:15:00+00:00'),
        );
    }
}
