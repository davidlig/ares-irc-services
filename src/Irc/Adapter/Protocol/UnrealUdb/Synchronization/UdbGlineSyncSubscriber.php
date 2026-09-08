<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Synchronization;

use App\OperServ\Application\PublishedEvent\GlineRemovedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function sprintf;

/**
 * Removes K-block records from the authoritative UDB store when a GLINE is
 * deleted or purged after expiry. GLINE creation is projected by the
 * protocol service actions (K::G records via the record writer).
 */
final class UdbGlineSyncSubscriber implements EventSubscriberInterface
{
    private const string BLOCK = 'K';

    public function __construct(
        private UdbRecordWriterInterface $recordWriter,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            GlineRemovedEvent::class => 'onGlineRemoved',
        ];
    }

    public function onGlineRemoved(GlineRemovedEvent $event): void
    {
        $this->recordWriter->delete(self::BLOCK, sprintf('G::%s', $event->mask));
    }
}
