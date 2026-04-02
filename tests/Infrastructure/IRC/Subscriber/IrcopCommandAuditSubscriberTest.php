<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Subscriber;

use App\Application\Port\DebugActionPort;
use App\Application\Security\IrcopPermissionDetector;
use App\Domain\IRC\Event\IrcopCommandExecutedEvent;
use App\Infrastructure\IRC\Subscriber\IrcopCommandAuditSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcopCommandAuditSubscriber::class)]
final class IrcopCommandAuditSubscriberTest extends TestCase
{
    public function testGetSubscribedEventsReturnsCorrectEvent(): void
    {
        $debug = $this->createStub(DebugActionPort::class);
        $detector = new IrcopPermissionDetector();
        $subscriber = new IrcopCommandAuditSubscriber($debug, $detector);

        $events = $subscriber::getSubscribedEvents();

        self::assertArrayHasKey(IrcopCommandExecutedEvent::class, $events);
        self::assertSame('onIrcopCommand', $events[IrcopCommandExecutedEvent::class]);
    }

    public function testOnIrcopCommandLogsForIrcopPermission(): void
    {
        $debug = $this->createMock(DebugActionPort::class);
        $detector = new IrcopPermissionDetector();
        $subscriber = new IrcopCommandAuditSubscriber($debug, $detector);

        $debug->expects(self::once())
            ->method('log')
            ->with(
                operator: 'Admin',
                command: 'KILL',
                target: 'BadUser',
                targetHost: 'user@host.com',
                targetIp: '10.0.0.1',
                reason: 'Flooding',
                extra: ['duration' => '1h'],
            );

        $event = new IrcopCommandExecutedEvent(
            operatorNick: 'Admin',
            commandName: 'KILL',
            permission: 'operserv.kill',
            target: 'BadUser',
            targetHost: 'user@host.com',
            targetIp: '10.0.0.1',
            reason: 'Flooding',
            extra: ['duration' => '1h'],
        );

        $subscriber->onIrcopCommand($event);
    }

    public function testOnIrcopCommandDoesNotLogForIdentifiedPermission(): void
    {
        $debug = $this->createMock(DebugActionPort::class);
        $detector = new IrcopPermissionDetector();
        $subscriber = new IrcopCommandAuditSubscriber($debug, $detector);

        $debug->expects(self::never())
            ->method('log');

        $event = new IrcopCommandExecutedEvent(
            operatorNick: 'User',
            commandName: 'REGISTER',
            permission: 'IDENTIFIED',
        );

        $subscriber->onIrcopCommand($event);
    }

    public function testOnIrcopCommandDoesNotLogForInvalidPermissionFormat(): void
    {
        $debug = $this->createMock(DebugActionPort::class);
        $detector = new IrcopPermissionDetector();
        $subscriber = new IrcopCommandAuditSubscriber($debug, $detector);

        $debug->expects(self::never())
            ->method('log');

        $event = new IrcopCommandExecutedEvent(
            operatorNick: 'User',
            commandName: 'SOME',
            permission: 'INVALID',
        );

        $subscriber->onIrcopCommand($event);
    }

    public function testOnIrcopCommandLogsWithNullValues(): void
    {
        $debug = $this->createMock(DebugActionPort::class);
        $detector = new IrcopPermissionDetector();
        $subscriber = new IrcopCommandAuditSubscriber($debug, $detector);

        $debug->expects(self::once())
            ->method('log')
            ->with(
                operator: 'Admin',
                command: 'GLINE',
                target: '',
                targetHost: null,
                targetIp: null,
                reason: null,
                extra: [],
            );

        $event = new IrcopCommandExecutedEvent(
            operatorNick: 'Admin',
            commandName: 'GLINE',
            permission: 'operserv.gline',
        );

        $subscriber->onIrcopCommand($event);
    }

    public function testOnIrcopCommandLogsForNestedPermission(): void
    {
        $debug = $this->createMock(DebugActionPort::class);
        $detector = new IrcopPermissionDetector();
        $subscriber = new IrcopCommandAuditSubscriber($debug, $detector);

        $debug->expects(self::once())
            ->method('log')
            ->with(
                operator: 'Root',
                command: 'ROLE',
                target: '',
                targetHost: null,
                targetIp: null,
                reason: null,
                extra: [],
            );

        $event = new IrcopCommandExecutedEvent(
            operatorNick: 'Root',
            commandName: 'ROLE',
            permission: 'operserv.admin.add',
        );

        $subscriber->onIrcopCommand($event);
    }
}
