<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Event;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Domain\Event\UserModeChangedEvent;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

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
            UserModeChangedEvent::class => ['onUserModeChanged', 0],
        ];
    }

    public function onUserModeChanged(UserModeChangedEvent $event): void
    {
        if ('-r' !== $event->modeDelta) {
            return;
        }

        $sender = $this->userLookup->findByUid($event->uid->value);
        if (null === $sender) {
            return;
        }

        $this->notifier->setUserVhost($event->uid->value, '', $sender->serverSid);
    }
}
