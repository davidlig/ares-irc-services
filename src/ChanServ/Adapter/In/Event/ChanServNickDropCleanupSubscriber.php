<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\UseCase\CleanupDroppedNick\CleanupDroppedNickData;
use App\ChanServ\Application\UseCase\CleanupDroppedNick\CleanupDroppedNickDataHandler;
use App\NickServ\Application\PublishedEvent\NickDropCleanupEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When a nick is dropped, clean up ChanServ data:
 * - Remove ACCESS entries for the nick (CASCADE DELETE)
 * - Clear creator reference in AKICK entries (SET NULL)
 * - Clear successor references where nick was successor (SET NULL)
 * - Handle channels where nick was founder (TRANSFER to successor or DROP channel).
 */
final readonly class ChanServNickDropCleanupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CleanupDroppedNickDataHandler $cleanupDroppedNickData,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NickDropCleanupEvent::class => ['onNickDrop', 0],
        ];
    }

    public function onNickDrop(NickDropCleanupEvent $event): void
    {
        $this->cleanupDroppedNickData->handle(new CleanupDroppedNickData($event->nickId, $event->occurredAt));
    }
}
