<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Event;

use App\ChanServ\Application\Port\In\RegisteredChannelSetup;
use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Shared\Application\Port\ServiceDebugNotifierInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Joins the debug channel when services connect (if configured) and applies
 * registered channel policies after sync completes.
 */
final readonly class DebugChannelJoinSubscriber implements EventSubscriberInterface
{
    /**
     * @param iterable<ServiceDebugNotifierInterface> $debugNotifiers
     */
    public function __construct(
        private iterable $debugNotifiers,
        private ?string $debugChannel,
        private RegisteredChannelSetup $registeredChannelSetup,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 0],
            NetworkSyncCompleteEvent::class => ['onSyncComplete', -30],
        ];
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        foreach ($this->debugNotifiers as $notifier) {
            $notifier->ensureChannelJoined();
        }
    }

    public function onSyncComplete(NetworkSyncCompleteEvent $event): void
    {
        if (null === $this->debugChannel || '' === $this->debugChannel) {
            return;
        }

        $this->registeredChannelSetup->restore($this->debugChannel);
    }
}
