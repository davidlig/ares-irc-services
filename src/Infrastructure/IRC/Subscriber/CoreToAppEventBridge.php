<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Subscriber;

use App\Application\Event\UserJoinedNetworkAppEvent;
use App\Application\Port\UserJoinedNetworkDTO;
use App\Domain\IRC\Event\UserJoinedNetworkEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Bridge subscriber: converts Core Domain events to Application-layer events for Services.
 *
 * Core IRC components dispatch Domain events with NetworkUser entities.
 * This subscriber transforms them into DTO-carrier App events that Services can safely consume.
 */
final readonly class CoreToAppEventBridge implements EventSubscriberInterface
{
    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedNetworkEvent::class => ['onUserJoinedNetwork', -128],
        ];
    }

    public function onUserJoinedNetwork(UserJoinedNetworkEvent $event): void
    {
        $user = $event->user;

        $dto = new UserJoinedNetworkDTO(
            uid: $user->uid->value,
            nick: $user->getNick()->value,
            ident: $user->ident->value,
            hostname: $user->hostname,
            cloakedHost: $user->cloakedHost,
            ipBase64: $user->ipBase64,
            displayHost: $user->getDisplayHost(),
            isIdentified: $user->isIdentified(),
            isOper: $user->isOper(),
            serverSid: $user->serverSid,
        );

        $this->eventDispatcher->dispatch(new UserJoinedNetworkAppEvent($dto));
    }
}
