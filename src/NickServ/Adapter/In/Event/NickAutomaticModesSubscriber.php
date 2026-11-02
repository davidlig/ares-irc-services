<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Event;

use App\Irc\Application\PublishedEvent\NetworkConnectionLostEvent;
use App\Irc\Application\PublishedEvent\UserJoinedNetworkAppEvent;
use App\Irc\Application\PublishedEvent\UserLeftNetworkEvent;
use App\Irc\Application\PublishedEvent\UserModesChangedEvent;
use App\NickServ\Application\Port\Out\IdentifiedSessionTracker;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\PublishedEvent\NickAutomaticModesChangedEvent;
use App\NickServ\Application\PublishedEvent\NickIdentifiedEvent;
use App\NickServ\Application\PublishedEvent\UserDeidentifiedEvent;
use App\NickServ\Application\Service\AutomaticModeSessionService;
use App\NickServ\Application\Service\NickHistoryService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function implode;

final readonly class NickAutomaticModesSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AutomaticModeSessionService $sessions,
        private IdentifiedSessionTracker $identities,
        private NickHistoryService $history,
        private NickNetworkUserLookup $users,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NickAutomaticModesChangedEvent::class => 'onChanged',
            NickIdentifiedEvent::class => 'onIdentified',
            UserDeidentifiedEvent::class => 'onDeidentified',
            UserModesChangedEvent::class => 'onModesChanged',
            UserLeftNetworkEvent::class => ['onQuit', 256],
            UserJoinedNetworkAppEvent::class => ['onJoined', 256],
            NetworkConnectionLostEvent::class => 'onConnectionLost',
        ];
    }

    public function onChanged(NickAutomaticModesChangedEvent $event): void
    {
        $this->history->recordAction(
            nickId: $event->nickId,
            action: [] === $event->after ? 'SASET_MODES_CLEAR' : 'SASET_MODES_SET',
            performedBy: $event->performedBy,
            performedByNickId: $event->performedByNickId,
            performedByIp: $event->performedByIp,
            performedByHost: $event->performedByHost,
            message: [] === $event->after ? 'history.message.modes_cleared' : 'history.message.modes_changed',
            performedAt: $event->occurredAt,
            extraData: ['old_modes' => implode(', ', $event->before), 'new_modes' => implode(', ', $event->after)],
        );
        $uid = $this->identities->findUidByNick($event->nickname);
        if (null !== $uid) {
            $this->sessions->apply($uid, $event->nickname);
        }
    }

    public function onIdentified(NickIdentifiedEvent $event): void
    {
        $this->sessions->apply($event->uid, $event->nickname);
    }

    public function onDeidentified(UserDeidentifiedEvent $event): void
    {
        $this->sessions->remove($event->uid);
    }

    public function onModesChanged(UserModesChangedEvent $event): void
    {
        $this->sessions->observe($event->uid, $event->modeDelta);
        $user = $this->users->findByUid($event->uid);
        if (null !== $user && !$user->isIdentified) {
            $this->sessions->remove($event->uid);
        }
    }

    public function onJoined(UserJoinedNetworkAppEvent $event): void
    {
        $this->sessions->forget($event->user->uid);
    }

    public function onQuit(UserLeftNetworkEvent $event): void
    {
        $this->sessions->forget($event->uid);
    }

    public function onConnectionLost(NetworkConnectionLostEvent $event): void
    {
        $this->sessions->forget();
    }
}
