<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network;

use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Domain\IRC\Network\NetworkStateAdapterInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to MessageReceivedEvent and delegates to the network state adapter
 * for the configured IRCd protocol. Only one adapter handles each message.
 */
class ProtocolNetworkStateRouter implements EventSubscriberInterface
{
    /** @var array<string, NetworkStateAdapterInterface> */
    public function __construct(
        private readonly string $protocol,
        private readonly array $adapters,
    ) {
    }

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
        $adapter = $this->adapters[$this->protocol] ?? null;
        if (null === $adapter) {
            return;
        }

        $adapter->handleMessage($event->message);
    }
}
