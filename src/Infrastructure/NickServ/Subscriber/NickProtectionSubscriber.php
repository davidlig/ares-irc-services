<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Subscriber;

use App\Application\NickServ\NickProtectionService;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Event\UserJoinedNetworkEvent;
use App\Domain\IRC\Event\UserNickChangedEvent;
use App\Domain\IRC\Event\UserQuitNetworkEvent;
use App\Infrastructure\IRC\ServiceBridge\CoreNetworkUserLookupAdapter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Thin subscriber: forwards IRC domain events to NickProtectionService.
 * Converts NetworkUser to SenderView via Core adapter so Application stays decoupled from Core entities.
 */
final readonly class NickProtectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly NickProtectionService $nickProtectionService,
        private readonly CoreNetworkUserLookupAdapter $senderViewMapper,
    ) {
    }

    /**
     * Priorities per Symfony 7.4 event_dispatcher: higher = runs earlier; range -256..256.
     *
     * @see https://symfony.com/doc/7.4/event_dispatcher.html
     */
    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedNetworkEvent::class => ['onUserJoined', 0],
            UserQuitNetworkEvent::class => ['onUserQuit', 0],
            UserNickChangedEvent::class => ['onNickChanged', 0],
            NetworkBurstCompleteEvent::class => ['onBurstComplete', -256],
        ];
    }

    public function onUserJoined(UserJoinedNetworkEvent $event): void
    {
        $senderView = $this->senderViewMapper->fromNetworkUser($event->user);
        $this->nickProtectionService->onUserJoined($senderView);
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        $this->nickProtectionService->onBurstComplete();
    }

    public function onNickChanged(UserNickChangedEvent $event): void
    {
        $this->nickProtectionService->onNickChanged($event);
    }

    public function onUserQuit(UserQuitNetworkEvent $event): void
    {
        $this->nickProtectionService->onUserQuit($event);
    }
}
