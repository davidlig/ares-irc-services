<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Event;

use App\Irc\Application\PublishedEvent\NetworkConnectionLostEvent;
use App\OperServ\Application\Port\Out\MotdCounterFlusher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

final readonly class FlushMotdCountersOnDisconnectSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MotdCounterFlusher $flusher,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [NetworkConnectionLostEvent::class => 'onDisconnect'];
    }

    public function onDisconnect(): void
    {
        try {
            $this->flusher->flushShownCounts();
        } catch (Throwable $exception) {
            $this->logger->error('Could not flush MOTD delivery statistics during disconnect.', [
                'exception' => $exception,
            ]);
        }
    }
}
