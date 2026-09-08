<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Subscriber;

use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Event\CommandExecutedEvent;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface as PublishedIrcopAuditableCommandInterface;
use App\Irc\Application\PublishedEvent\CommandExecutedEvent as PublishedCommandExecutedEvent;
use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\In\OperatorAuthorizationAttribute;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class CommandExecutedAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CommandAuditRecorder $audit,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            CommandExecutedEvent::class => 'onCommandExecuted',
            PublishedCommandExecutedEvent::class => 'onPublishedCommandExecuted',
        ];
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

        $this->record(
            service: $event->serviceName,
            actor: $event->operatorNick,
            operation: $event->commandName,
            permission: $event->permission,
            target: $event->outcome->auditData->target,
            targetHost: $event->outcome->auditData->targetHost,
            targetIp: $event->outcome->auditData->targetIp,
            reason: $event->outcome->auditData->reason,
            metadata: $event->outcome->auditData->extra,
        );
    }

    public function onPublishedCommandExecuted(PublishedCommandExecutedEvent $event): void
    {
        if (!$event->command instanceof PublishedIrcopAuditableCommandInterface
            || null === $event->permission
            || null === $event->outcome
            || !$event->outcome->success
            || null === $event->outcome->auditData
        ) {
            return;
        }

        $this->record(
            service: $event->serviceName,
            actor: $event->operatorNick,
            operation: $event->commandName,
            permission: $event->permission,
            target: $event->outcome->auditData->target,
            targetHost: $event->outcome->auditData->targetHost,
            targetIp: $event->outcome->auditData->targetIp,
            reason: $event->outcome->auditData->reason,
            metadata: $event->outcome->auditData->extra,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function record(
        string $service,
        string $actor,
        string $operation,
        string $permission,
        string $target,
        ?string $targetHost,
        ?string $targetIp,
        ?string $reason,
        array $metadata,
    ): void {
        $category = OperatorAuthorizationAttribute::ROOT === $permission
            ? CommandAuditCategory::RootAdministration
            : CommandAuditCategory::OperatorAction;

        $this->audit->record(new CommandAuditRecord(
            category: $category,
            service: $service,
            actor: $actor,
            operation: $operation,
            occurredAt: new DateTimeImmutable(),
            permission: $permission,
            target: $target,
            targetHost: $targetHost,
            targetIp: $targetIp,
            reason: $reason,
            metadata: $metadata,
        ));
    }
}
