<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Logging;

use App\Domain\IRC\Event\ConnectionEstablishedEvent;
use App\Domain\IRC\Event\ConnectionLostEvent;
use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Infrastructure\IRC\Security\SensitiveDataRedactor;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Logs all IRC domain events to the dedicated 'irc' Monolog channel.
 *
 * Levels used:
 *   INFO    — link lifecycle (established, EOS)
 *   WARNING — unexpected link loss
 *   DEBUG   — every incoming IRC message
 */
final readonly class IRCEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
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
            ConnectionEstablishedEvent::class => ['onConnectionEstablished', 0],
            ConnectionLostEvent::class => ['onConnectionLost', 0],
            MessageReceivedEvent::class => ['onMessageReceived', 0],
        ];
    }

    public function onConnectionEstablished(ConnectionEstablishedEvent $event): void
    {
        $this->logger->info('Server link established.', [
            'server' => (string) $event->serverLink->serverName,
            'host' => (string) $event->serverLink->host,
            'port' => $event->serverLink->port->value,
            'tls' => $event->serverLink->useTls,
            'occurred' => $event->occurredAt->format(DateTimeInterface::ATOM),
        ]);
    }

    public function onConnectionLost(ConnectionLostEvent $event): void
    {
        $this->logger->warning('Server link lost.', [
            'server' => (string) $event->serverLink->serverName,
            'reason' => $event->reason ?? 'unknown',
            'occurred' => $event->occurredAt->format(DateTimeInterface::ATOM),
        ]);
    }

    public function onMessageReceived(MessageReceivedEvent $event): void
    {
        $message = $event->message;
        $trailing = $message->trailing;
        $raw = $message->toRawLine();

        if ('PRIVMSG' === $message->command && null !== $trailing) {
            $redacted = SensitiveDataRedactor::redactNickServCommand($trailing);
            if ($redacted !== $trailing) {
                $trailing = $redacted;
                $raw = preg_replace('/(\s:).*$/', '$1' . $redacted, $raw) ?? $raw;
            }
        }

        $this->logger->debug('< ' . $message->command, [
            'prefix' => $message->prefix,
            'params' => $message->params,
            'trailing' => $trailing,
            'raw' => $raw,
        ]);
    }
}
