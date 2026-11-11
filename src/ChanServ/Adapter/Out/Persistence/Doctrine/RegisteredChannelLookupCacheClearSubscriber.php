<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\Irc\Application\PublishedEvent\IrcMessageProcessingFinishedEvent;
use App\Irc\Application\PublishedEvent\IrcMessageProcessingStartedEvent;
use App\Irc\Application\PublishedEvent\NetworkConnectionLostEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class RegisteredChannelLookupCacheClearSubscriber implements EventSubscriberInterface
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
        RegisteredChannelDoctrineRepository::beginLookupCacheLine();
    }

    public function onIrcMessageProcessingFinished(): void
    {
        RegisteredChannelDoctrineRepository::resetLookupCache();
    }

    public function onNetworkConnectionLost(): void
    {
        RegisteredChannelDoctrineRepository::resetLookupCache();
    }
}
