<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\Port\In\ForbiddenChannelEnforcement;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ChanServForbiddenChannelBurstSubscriber implements EventSubscriberInterface
{
    public function __construct(private ForbiddenChannelEnforcement $enforcement) {}

    public static function getSubscribedEvents(): array
    {
        return [NetworkSynchronizationCompletedEvent::class => ['onNetworkSyncComplete', 10]];
    }

    public function onNetworkSyncComplete(NetworkSynchronizationCompletedEvent $event): void
    {
        $this->enforcement->enforceAllForbiddenChannels();
    }
}
