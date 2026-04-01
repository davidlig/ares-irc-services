<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Subscriber;

use App\Application\Port\DebugActionPort;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Joins the debug channel when services connect (if configured).
 *
 * The debug channel receives OPER.DEBUG action logs for IRCop commands.
 * ChanServ joins this channel to receive the logs.
 */
final readonly class DebugChannelJoinSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private DebugActionPort $debugAction,
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
        $this->debugAction->ensureChannelJoined();
    }
}
