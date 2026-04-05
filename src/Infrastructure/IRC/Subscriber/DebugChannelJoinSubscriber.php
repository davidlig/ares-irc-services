<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Subscriber;

use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Joins the debug channel when services connect (if configured).
 *
 * The debug channel receives OPER.DEBUG action logs for IRCop commands.
 * Each service (NickServ, ChanServ, OperServ) joins its debug channel if configured.
 */
final readonly class DebugChannelJoinSubscriber implements EventSubscriberInterface
{
    /**
     * @param iterable<ServiceDebugNotifierInterface> $debugNotifiers
     */
    public function __construct(
        private iterable $debugNotifiers,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 0],
        ];
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        foreach ($this->debugNotifiers as $notifier) {
            $notifier->ensureChannelJoined();
        }
    }
}
