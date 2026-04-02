<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Subscriber;

use App\Application\Port\DebugActionPort;
use App\Application\Security\IrcopPermissionDetector;
use App\Domain\IRC\Event\IrcopCommandExecutedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class IrcopCommandAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly DebugActionPort $debug,
        private readonly IrcopPermissionDetector $detector,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            IrcopCommandExecutedEvent::class => 'onIrcopCommand',
        ];
    }

    public function onIrcopCommand(IrcopCommandExecutedEvent $event): void
    {
        if (!$this->detector->isIrcopPermission($event->permission)) {
            return;
        }

        $this->debug->log(
            operator: $event->operatorNick,
            command: $event->commandName,
            target: $event->target ?? '',
            targetHost: $event->targetHost,
            targetIp: $event->targetIp,
            reason: $event->reason,
            extra: $event->extra,
        );
    }
}
