<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Event;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\PublishedEvent\UserModesChangedEvent;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function str_contains;

/**
 * When a user loses the +r (identified) mode, clear their vhost so the displayed
 * host reverts to cloak/real host. Covers nick change (Core strips +r) and
 * explicit logout; also users who had +r from SASL and were never in
 * IdentifiedSessionRegistry.
 */
final readonly class VhostClearOnDeidentifySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private NetworkUserLookupPort $userLookup,
        private NickServNotifierInterface $notifier,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            UserModesChangedEvent::class => ['onUserModeChanged', 0],
        ];
    }

    public function onUserModeChanged(UserModesChangedEvent $event): void
    {
        if (!str_contains($event->modeDelta, 'r')) {
            return;
        }

        $sender = $this->userLookup->findByUid($event->uid);
        if (null === $sender || $sender->isIdentified) {
            return;
        }

        $this->notifier->setUserVhost($event->uid, '', $sender->serverSid);
    }
}
