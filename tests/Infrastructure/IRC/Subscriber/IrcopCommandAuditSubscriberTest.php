<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Subscriber;

use App\Application\Port\ServiceDebugNotifierInterface;
use App\Application\Port\ServiceDebugNotifierRegistry;
use App\Application\Security\IrcopPermissionDetector;
use App\Domain\IRC\Event\IrcopCommandExecutedEvent;
use App\Infrastructure\IRC\Subscriber\IrcopCommandAuditSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcopCommandAuditSubscriber::class)]
final class IrcopCommandAuditSubscriberTest extends TestCase
{
    #[Test]
    public function getSubscribedEventsReturnsCorrectEvent(): void
    {
        $registry = new ServiceDebugNotifierRegistry([]);
        $detector = new IrcopPermissionDetector();
        $subscriber = new IrcopCommandAuditSubscriber($registry, $detector);

        $events = $subscriber::getSubscribedEvents();

        self::assertArrayHasKey(IrcopCommandExecutedEvent::class, $events);
        self::assertSame('onIrcopCommand', $events[IrcopCommandExecutedEvent::class]);
    }

    #[Test]
    public function onIrcopCommandLogsForIrcopPermission(): void
    {
        $notifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $notifier->method('getServiceName')->willReturn('nickserv');
        $notifier->method('isConfigured')->willReturn(true);
        $notifier->expects(self::once())->method('ensureChannelJoined');
        $notifier->expects(self::once())
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

        $registry = new ServiceDebugNotifierRegistry([$notifier]);
        $detector = new IrcopPermissionDetector();
        $subscriber = new IrcopCommandAuditSubscriber($registry, $detector);

        $event = new IrcopCommandExecutedEvent(
            serviceName: 'nickserv',
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

    #[Test]
    public function onIrcopCommandDoesNotLogForIdentifiedPermission(): void
    {
        $notifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $notifier->expects(self::never())->method('log');

        $registry = new ServiceDebugNotifierRegistry([$notifier]);
        $detector = new IrcopPermissionDetector();
        $subscriber = new IrcopCommandAuditSubscriber($registry, $detector);

        $event = new IrcopCommandExecutedEvent(
            serviceName: 'nickserv',
            operatorNick: 'User',
            commandName: 'REGISTER',
            permission: 'IDENTIFIED',
        );

        $subscriber->onIrcopCommand($event);
    }

    #[Test]
    public function onIrcopCommandDoesNotLogForInvalidPermissionFormat(): void
    {
        $notifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $notifier->expects(self::never())->method('log');

        $registry = new ServiceDebugNotifierRegistry([$notifier]);
        $detector = new IrcopPermissionDetector();
        $subscriber = new IrcopCommandAuditSubscriber($registry, $detector);

        $event = new IrcopCommandExecutedEvent(
            serviceName: 'nickserv',
            operatorNick: 'User',
            commandName: 'SOME',
            permission: 'INVALID',
        );

        $subscriber->onIrcopCommand($event);
    }

    #[Test]
    public function onIrcopCommandLogsWithNullValues(): void
    {
        $notifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $notifier->method('getServiceName')->willReturn('operserv');
        $notifier->method('isConfigured')->willReturn(true);
        $notifier->expects(self::once())->method('ensureChannelJoined');
        $notifier->expects(self::once())
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

        $registry = new ServiceDebugNotifierRegistry([$notifier]);
        $detector = new IrcopPermissionDetector();
        $subscriber = new IrcopCommandAuditSubscriber($registry, $detector);

        $event = new IrcopCommandExecutedEvent(
            serviceName: 'operserv',
            operatorNick: 'Admin',
            commandName: 'GLINE',
            permission: 'operserv.gline',
        );

        $subscriber->onIrcopCommand($event);
    }

    #[Test]
    public function onIrcopCommandLogsForNestedPermission(): void
    {
        $notifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $notifier->method('getServiceName')->willReturn('operserv');
        $notifier->method('isConfigured')->willReturn(true);
        $notifier->expects(self::once())->method('ensureChannelJoined');
        $notifier->expects(self::once())
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

        $registry = new ServiceDebugNotifierRegistry([$notifier]);
        $detector = new IrcopPermissionDetector();
        $subscriber = new IrcopCommandAuditSubscriber($registry, $detector);

        $event = new IrcopCommandExecutedEvent(
            serviceName: 'operserv',
            operatorNick: 'Root',
            commandName: 'ROLE',
            permission: 'operserv.admin.add',
        );

        $subscriber->onIrcopCommand($event);
    }

    #[Test]
    public function onIrcopCommandDoesNotLogWhenNotifierNotConfigured(): void
    {
        $notifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $notifier->method('getServiceName')->willReturn('nickserv');
        $notifier->method('isConfigured')->willReturn(false);
        $notifier->expects(self::never())->method('ensureChannelJoined');
        $notifier->expects(self::never())->method('log');

        $registry = new ServiceDebugNotifierRegistry([$notifier]);
        $detector = new IrcopPermissionDetector();
        $subscriber = new IrcopCommandAuditSubscriber($registry, $detector);

        $event = new IrcopCommandExecutedEvent(
            serviceName: 'nickserv',
            operatorNick: 'Admin',
            commandName: 'KILL',
            permission: 'operserv.kill',
            target: 'BadUser',
        );

        $subscriber->onIrcopCommand($event);
    }

    #[Test]
    public function onIrcopCommandDoesNotLogWhenNotifierNotFound(): void
    {
        $registry = new ServiceDebugNotifierRegistry([]);
        $detector = new IrcopPermissionDetector();
        $subscriber = new IrcopCommandAuditSubscriber($registry, $detector);

        $event = new IrcopCommandExecutedEvent(
            serviceName: 'unknownservice',
            operatorNick: 'Admin',
            commandName: 'KILL',
            permission: 'operserv.kill',
            target: 'BadUser',
        );

        $subscriber->onIrcopCommand($event);

        self::assertTrue(true);
    }
}
