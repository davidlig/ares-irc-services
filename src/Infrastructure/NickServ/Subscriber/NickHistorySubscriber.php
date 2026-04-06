<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Subscriber;

use App\Application\NickServ\Service\NickHistoryService;
use App\Domain\NickServ\Event\NickEmailChangedEvent;
use App\Domain\NickServ\Event\NickPasswordChangedEvent;
use App\Domain\NickServ\Event\NickRecoveredEvent;
use App\Domain\NickServ\Event\NickSuspendedEvent;
use App\Domain\NickServ\Event\NickUnsuspendedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Listens to nickname events and records history entries automatically.
 *
 * Messages are stored as translation keys (e.g., 'history.message.suspend')
 * and translated when viewed via HISTORY VIEW command.
 */
final readonly class NickHistorySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private NickHistoryService $historyService,
        private RegisteredNickRepositoryInterface $nickRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NickSuspendedEvent::class => ['onNickSuspended', 0],
            NickUnsuspendedEvent::class => ['onNickUnsuspended', 0],
            NickPasswordChangedEvent::class => ['onNickPasswordChanged', 0],
            NickEmailChangedEvent::class => ['onNickEmailChanged', 0],
            NickRecoveredEvent::class => ['onNickRecovered', 0],
        ];
    }

    public function onNickSuspended(NickSuspendedEvent $event): void
    {
        $extra = [];
        if (null !== $event->duration) {
            $extra['duration'] = $event->duration;
        }
        if (null !== $event->expiresAt) {
            $extra['expires_at'] = $event->expiresAt->format('Y-m-d H:i:s');
        }

        $this->historyService->recordAction(
            nickId: $event->nickId,
            action: 'SUSPEND',
            performedBy: $event->performedBy,
            performedByNickId: $event->performedByNickId,
            performedByIp: $event->performedByIp,
            performedByHost: $event->performedByHost,
            message: $event->reason,
            extraData: $extra,
            performedAt: $event->occurredAt,
        );
    }

    public function onNickUnsuspended(NickUnsuspendedEvent $event): void
    {
        $this->historyService->recordAction(
            nickId: $event->nickId,
            action: 'UNSUSPEND',
            performedBy: $event->performedBy,
            performedByNickId: $event->performedByNickId,
            performedByIp: $event->performedByIp,
            performedByHost: $event->performedByHost,
            message: 'history.message.unsuspend',
            performedAt: $event->occurredAt,
        );
    }

    public function onNickPasswordChanged(NickPasswordChangedEvent $event): void
    {
        $action = $event->changedByOwner ? 'SET_PASSWORD' : 'SASET_PASSWORD';
        $messageKey = $event->changedByOwner
            ? 'history.message.password_changed_owner'
            : 'history.message.password_changed_operator';

        $this->historyService->recordAction(
            nickId: $event->nickId,
            action: $action,
            performedBy: $event->performedBy,
            performedByNickId: $event->performedByNickId,
            performedByIp: $event->performedByIp,
            performedByHost: $event->performedByHost,
            message: $messageKey,
            performedAt: $event->occurredAt,
        );
    }

    public function onNickEmailChanged(NickEmailChangedEvent $event): void
    {
        $action = $event->changedByOwner ? 'SET_EMAIL' : 'SASET_EMAIL';
        $messageKey = $event->changedByOwner
            ? 'history.message.email_changed_owner'
            : 'history.message.email_changed_operator';

        $extra = [
            'old_email' => $event->oldEmail,
            'new_email' => $event->newEmail,
        ];

        $this->historyService->recordAction(
            nickId: $event->nickId,
            action: $action,
            performedBy: $event->performedBy,
            performedByNickId: $event->performedByNickId,
            performedByIp: $event->performedByIp,
            performedByHost: $event->performedByHost,
            message: $messageKey,
            extraData: $extra,
            performedAt: $event->occurredAt,
        );
    }

    public function onNickRecovered(NickRecoveredEvent $event): void
    {
        $extra = [
            'method' => $event->method,
        ];

        $this->historyService->recordAction(
            nickId: $event->nickId,
            action: 'RECOVER',
            performedBy: $event->performedBy,
            performedByNickId: $event->performedByNickId,
            performedByIp: $event->performedByIp,
            performedByHost: $event->performedByHost,
            message: 'history.message.recover',
            extraData: $extra,
            performedAt: $event->occurredAt,
        );
    }
}
