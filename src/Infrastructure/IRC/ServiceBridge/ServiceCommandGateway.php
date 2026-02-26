<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\ServiceBridge;

use App\Application\Port\ServiceCommandListenerInterface;
use App\Domain\IRC\Event\MessageReceivedEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Single entry point for PRIVMSG targeting a service. Listens to MessageReceivedEvent,
 * matches target to registered service names, and invokes the listener with (senderUid, text).
 *
 * Bots register via ServiceCommandListenerInterface (tagged); they never subscribe
 * to MessageReceivedEvent directly.
 */
final readonly class ServiceCommandGateway implements EventSubscriberInterface
{
    /** @var array<string, ServiceCommandListenerInterface> target (lowercase nick or UID) => listener */
    private array $listeners;

    public function __construct(
        iterable $listeners,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->listeners = $this->buildListenerMap($listeners);
    }

    /**
     * @param iterable<ServiceCommandListenerInterface> $listeners
     *
     * @return array<string, ServiceCommandListenerInterface>
     */
    private function buildListenerMap(iterable $listeners): array
    {
        $map = [];
        foreach ($listeners as $listener) {
            if (!$listener instanceof ServiceCommandListenerInterface) {
                continue;
            }
            $map[strtolower($listener->getServiceName())] = $listener;
            $uid = $listener->getServiceUid();
            if (null !== $uid && '' !== $uid) {
                $map[$uid] = $listener;
            }
        }

        return $map;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MessageReceivedEvent::class => ['onMessage', 0],
        ];
    }

    public function onMessage(MessageReceivedEvent $event): void
    {
        $message = $event->message;

        if ('PRIVMSG' !== $message->command) {
            return;
        }

        $target = $message->params[0] ?? '';
        $text = $message->trailing ?? '';
        $sourceId = $message->prefix ?? '';

        if ('' === $target || '' === $sourceId) {
            return;
        }

        $targetKey = strtolower($target);
        $listener = $this->listeners[$targetKey] ?? $this->listeners[$target] ?? null;

        if (null === $listener) {
            return;
        }

        $this->logger->debug('Service command gateway: dispatching to {service}', [
            'service' => $listener->getServiceName(),
            'sender' => $sourceId,
        ]);

        $listener->onCommand($sourceId, $text ?? '');
    }
}
