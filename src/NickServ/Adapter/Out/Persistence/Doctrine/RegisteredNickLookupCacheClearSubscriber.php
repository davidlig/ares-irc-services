<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Persistence\Doctrine;

use App\Irc\Application\PublishedEvent\IrcMessageProcessingFinishedEvent;
use App\Irc\Application\PublishedEvent\IrcMessageProcessingStartedEvent;
use App\Irc\Application\PublishedEvent\NetworkConnectionLostEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class RegisteredNickLookupCacheClearSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            IrcMessageProcessingStartedEvent::class => ['onIrcMessageProcessingStarted', 1024],
            IrcMessageProcessingFinishedEvent::class => ['onIrcMessageProcessingFinished', -256],
            NetworkConnectionLostEvent::class => 'onNetworkConnectionLost',
        ];
    }

    public function onIrcMessageProcessingStarted(): void
    {
        RegisteredNickDoctrineRepository::beginLookupCacheLine();
    }

    public function onIrcMessageProcessingFinished(): void
    {
        RegisteredNickDoctrineRepository::resetLookupCache();
    }

    public function onNetworkConnectionLost(): void
    {
        RegisteredNickDoctrineRepository::resetLookupCache();
    }
}
