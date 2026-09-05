<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Subscriber;

use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Event\CommandExecutedEvent;
use App\Application\Event\IrcopCommandExecutedEvent;
use App\Application\Port\EventBusInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class CommandExecutedAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EventBusInterface $eventBus,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [CommandExecutedEvent::class => 'onCommandExecuted'];
    }

    public function onCommandExecuted(CommandExecutedEvent $event): void
    {
        if (!$event->command instanceof IrcopAuditableCommandInterface
            || null === $event->permission
            || null === $event->outcome
            || !$event->outcome->success
            || null === $event->outcome->auditData
        ) {
            return;
        }

        $auditData = $event->outcome->auditData;
        $this->eventBus->dispatch(new IrcopCommandExecutedEvent(
            serviceName: $event->serviceName,
            operatorNick: $event->operatorNick,
            commandName: $event->commandName,
            permission: $event->permission,
            target: $auditData->target,
            targetHost: $auditData->targetHost,
            targetIp: $auditData->targetIp,
            reason: $auditData->reason,
            extra: $auditData->extra,
        ));
    }
}
