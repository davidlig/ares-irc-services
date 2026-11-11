<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\Out\Persistence\ChannelActivityBuffer;
use App\Irc\Application\PublishedEvent\NetworkConnectionLostEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

final readonly class ChanServChannelActivityFlushSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ChannelActivityBuffer $activityBuffer,
        private LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [NetworkConnectionLostEvent::class => 'onConnectionLost'];
    }

    public function onConnectionLost(NetworkConnectionLostEvent $event): void
    {
        try {
            $this->activityBuffer->flush();
        } catch (Throwable $exception) {
            $this->logger->error('Could not flush deferred ChanServ channel activity during shutdown.', [
                'exception' => $exception,
            ]);
        }
    }
}
