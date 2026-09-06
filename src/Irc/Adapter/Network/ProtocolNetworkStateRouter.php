<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network;

use App\Irc\Adapter\Event\MessageReceivedEvent;
use App\Irc\Adapter\Protocol\NetworkStateAdapterInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to MessageReceivedEvent and delegates to the network state adapter
 * for the configured IRCd protocol. Only one adapter handles each message.
 */
final class ProtocolNetworkStateRouter implements EventSubscriberInterface
{
    public function __construct(
        private readonly NetworkStateAdapterInterface $adapter,
    ) {}

    /**
     * Priorities per Symfony 7.4 event_dispatcher: higher = runs earlier; range -256..256.
     *
     * @see https://symfony.com/doc/7.4/event_dispatcher.html
     */
    public static function getSubscribedEvents(): array
    {
        return [
            MessageReceivedEvent::class => ['onMessageReceived', 0],
        ];
    }

    public function onMessageReceived(MessageReceivedEvent $event): void
    {
        $this->adapter->handleMessage($event->message);
    }
}
