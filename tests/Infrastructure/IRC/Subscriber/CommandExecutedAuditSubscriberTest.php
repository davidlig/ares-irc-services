<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Subscriber;

use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\Event\CommandExecutedEvent;
use App\Application\Event\IrcopCommandExecutedEvent;
use App\Application\Port\EventBusInterface;
use App\Infrastructure\IRC\Subscriber\CommandExecutedAuditSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(CommandExecutedAuditSubscriber::class)]
final class CommandExecutedAuditSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToCommandExecution(): void
    {
        self::assertSame(
            [CommandExecutedEvent::class => 'onCommandExecuted'],
            CommandExecutedAuditSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function ignoresCommandWithoutMarker(): void
    {
        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->expects(self::never())->method('dispatch');

        $this->subscriber($eventBus)->onCommandExecuted($this->event(new stdClass(), CommandOutcome::success(new IrcopAuditData('target'))));
    }

    #[Test]
    public function ignoresRejectedOutcome(): void
    {
        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->expects(self::never())->method('dispatch');

        $this->subscriber($eventBus)->onCommandExecuted($this->event($this->auditableCommand(), CommandOutcome::rejected()));
    }

    #[Test]
    public function ignoresSuccessfulOutcomeWithoutAuditData(): void
    {
        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->expects(self::never())->method('dispatch');

        $this->subscriber($eventBus)->onCommandExecuted($this->event($this->auditableCommand(), CommandOutcome::success()));
    }

    #[Test]
    public function ignoresMissingPermissionOrOutcome(): void
    {
        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->expects(self::never())->method('dispatch');
        $subscriber = $this->subscriber($eventBus);
        $command = $this->auditableCommand();

        $subscriber->onCommandExecuted(new CommandExecutedEvent($command, 'operserv', 'Oper', 'RAW', null, CommandOutcome::success(new IrcopAuditData('line'))));
        $subscriber->onCommandExecuted(new CommandExecutedEvent($command, 'operserv', 'Oper', 'RAW', 'operserv.raw', null));
    }

    #[Test]
    public function dispatchesExistingAuditEventForSuccessfulOutcome(): void
    {
        $eventBus = $this->createMock(EventBusInterface::class);
        $eventBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (IrcopCommandExecutedEvent $event): bool => 'operserv' === $event->serviceName
                && 'Oper' === $event->operatorNick
                && 'RAW' === $event->commandName
                && 'operserv.raw' === $event->permission
                && 'line' === $event->target
                && 'ident@host' === $event->targetHost
                && '127.0.0.1' === $event->targetIp
                && 'reason' === $event->reason
                && ['key' => 'value'] === $event->extra));

        $outcome = CommandOutcome::success(new IrcopAuditData('line', 'ident@host', '127.0.0.1', 'reason', ['key' => 'value']));

        $this->subscriber($eventBus)->onCommandExecuted($this->event($this->auditableCommand(), $outcome));
    }

    private function subscriber(EventBusInterface $eventBus): CommandExecutedAuditSubscriber
    {
        return new CommandExecutedAuditSubscriber($eventBus);
    }

    private function auditableCommand(): object
    {
        return new class implements IrcopAuditableCommandInterface {};
    }

    private function event(object $command, ?CommandOutcome $outcome): CommandExecutedEvent
    {
        return new CommandExecutedEvent($command, 'operserv', 'Oper', 'RAW', 'operserv.raw', $outcome);
    }
}
