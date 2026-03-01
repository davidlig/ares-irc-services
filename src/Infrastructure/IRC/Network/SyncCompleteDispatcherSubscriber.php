<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network;

use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function in_array;

/**
 * Dispatches NetworkSyncCompleteEvent when EOS (Unreal) or ENDBURST (InspIRCd) is received,
 * after the message has been processed (MessageReceivedEvent). This ensures MLOCK and
 * rejoin run with the final burst state.
 */
final readonly class SyncCompleteDispatcherSubscriber implements EventSubscriberInterface
{
    private const array SYNC_COMPLETE_COMMANDS = ['EOS', 'ENDBURST'];

    public function __construct(
        private ActiveConnectionHolder $connectionHolder,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MessageReceivedEvent::class => ['onMessageReceived', -10],
        ];
    }

    public function onMessageReceived(MessageReceivedEvent $event): void
    {
        if (!in_array($event->message->command, self::SYNC_COMPLETE_COMMANDS, true)) {
            return;
        }

        $connection = $this->connectionHolder->getConnection();
        $sid = $this->connectionHolder->getServerSid();
        if (null === $connection || null === $sid || '' === $sid) {
            return;
        }

        $this->eventDispatcher->dispatch(new NetworkSyncCompleteEvent($connection, $sid));
    }
}
