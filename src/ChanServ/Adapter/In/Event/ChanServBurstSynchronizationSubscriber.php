<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\Model\BurstRankEventScope;
use App\ChanServ\Application\UseCase\SynchronizeBurst\SynchronizeBurstChannels;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ChanServBurstSynchronizationSubscriber implements EventSubscriberInterface
{
    public function __construct(private SynchronizeBurstChannels $handler, private BurstRankEventScope $scope) {}

    public static function getSubscribedEvents(): array
    {
        return [NetworkSynchronizationCompletedEvent::class => ['onSyncComplete', 10]];
    }

    public function onSyncComplete(): void
    {
        $this->scope->begin();
        try {
            $this->handler->handle(new DateTimeImmutable());
        } finally {
            $this->scope->end();
        }
    }
}
