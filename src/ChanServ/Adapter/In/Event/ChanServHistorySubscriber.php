<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\PublishedEvent\ChannelAccessChangedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelAkickChangedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelFounderChangedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelSuccessorChangedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelSuspendedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelUnsuspendedEvent;
use App\ChanServ\Application\Service\ChannelHistoryService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ChanServHistorySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ChannelHistoryService $historyService,
        private ChanUserAccountPort $accountPort,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelSuspendedEvent::class => ['onChannelSuspended', 0],
            ChannelUnsuspendedEvent::class => ['onChannelUnsuspended', 0],
            ChannelFounderChangedEvent::class => ['onChannelFounderChanged', 0],
            ChannelSuccessorChangedEvent::class => ['onChannelSuccessorChanged', 0],
            ChannelAccessChangedEvent::class => ['onChannelAccessChanged', 0],
            ChannelAkickChangedEvent::class => ['onChannelAkickChanged', 0],
        ];
    }

    public function onChannelSuspended(ChannelSuspendedEvent $event): void
    {
        $extra = [];
        if (null !== $event->duration) {
            $extra['duration'] = $event->duration;
        }
        if (null !== $event->expiresAt) {
            $extra['expires_at'] = $event->expiresAt->format('Y-m-d H:i:s');
        }

        $this->historyService->recordAction(
            channelId: $event->channelId,
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

    public function onChannelUnsuspended(ChannelUnsuspendedEvent $event): void
    {
        $this->historyService->recordAction(
            channelId: $event->channelId,
            action: 'UNSUSPEND',
            performedBy: $event->performedBy,
            performedByNickId: $event->performedByNickId,
            performedByIp: $event->performedByIp,
            performedByHost: $event->performedByHost,
            message: 'history.message.unsuspend',
            performedAt: $event->occurredAt,
        );
    }

    public function onChannelFounderChanged(ChannelFounderChangedEvent $event): void
    {
        $oldFounder = $this->resolveNickName($event->oldFounderNickId);
        $newFounder = $this->resolveNickName($event->newFounderNickId);

        $extra = [
            'old_founder' => $oldFounder,
            'new_founder' => $newFounder,
        ];

        if ($event->byOperator) {
            $extra['by_operator'] = true;
        }

        $this->historyService->recordAction(
            channelId: $event->channelId,
            action: 'SET_FOUNDER',
            performedBy: $event->performedBy,
            performedByNickId: $event->performedByNickId,
            performedByIp: $event->performedByIp,
            performedByHost: $event->performedByHost,
            message: 'history.message.founder_changed',
            extraData: $extra,
            performedAt: $event->occurredAt,
        );
    }

    public function onChannelSuccessorChanged(ChannelSuccessorChangedEvent $event): void
    {
        $oldSuccessor = null !== $event->oldSuccessorNickId
            ? $this->resolveNickName($event->oldSuccessorNickId)
            : null;
        $newSuccessor = null !== $event->newSuccessorNickId
            ? $this->resolveNickName($event->newSuccessorNickId)
            : null;

        $action = null !== $event->newSuccessorNickId ? 'SET_SUCCESSOR' : 'CLEAR_SUCCESSOR';

        $messageKey = null !== $event->newSuccessorNickId
            ? 'history.message.successor_changed'
            : 'history.message.successor_cleared';

        $extra = [
            'old_successor' => $oldSuccessor ?? '(none)',
            'new_successor' => $newSuccessor ?? '(none)',
        ];

        $this->historyService->recordAction(
            channelId: $event->channelId,
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

    public function onChannelAccessChanged(ChannelAccessChangedEvent $event): void
    {
        $extra = [
            'target_nickname' => $event->targetNickname,
        ];

        if (null !== $event->level) {
            $extra['level'] = (string) $event->level;
        }

        $action = 'ADD' === $event->action ? 'ACCESS_ADD' : 'ACCESS_DEL';

        $messageKey = 'ADD' === $event->action
            ? 'history.message.access_add'
            : 'history.message.access_del';

        $this->historyService->recordAction(
            channelId: $event->channelId,
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

    public function onChannelAkickChanged(ChannelAkickChangedEvent $event): void
    {
        $extra = [
            'mask' => $event->mask,
        ];

        if (null !== $event->reason) {
            $extra['reason'] = $event->reason;
        }

        $action = 'ADD' === $event->action ? 'AKICK_ADD' : 'AKICK_DEL';

        $messageKey = 'ADD' === $event->action
            ? 'history.message.akick_add'
            : 'history.message.akick_del';

        $this->historyService->recordAction(
            channelId: $event->channelId,
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

    private function resolveNickName(int $nickId): string
    {
        $account = $this->accountPort->findAccountById($nickId);

        return null !== $account ? $account->nickname : (string) $nickId;
    }
}
