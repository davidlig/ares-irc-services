<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\Out\Persistence\ChannelActivityBuffer;
use App\Irc\Application\PublishedEvent\NetworkConnectionLostEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Flushes deferred channel activity on disconnection. The buffer retains failed chunks for the
 * next flush; the reported failure is logged here so a database failure never breaks shutdown.
 */
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
        } catch (\Throwable $e) {
            $this->logger->warning('Deferred ChanServ channel activity was not fully flushed on disconnection; it stays pending.', ['exception' => $e]);
        }
    }
}
