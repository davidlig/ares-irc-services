<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Logging;

use App\Irc\Adapter\Event\ConnectionEstablishedEvent;
use App\Irc\Adapter\Event\ConnectionLostEvent;
use App\Irc\Adapter\Event\IncomingIrcMessageEvent;
use App\Irc\Adapter\Security\SensitiveDataRedactor;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function in_array;

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
        private LoggerInterface $logger,
    ) {}

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
            IncomingIrcMessageEvent::class => ['onIncomingMessage', 0],
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

    public function onIncomingMessage(IncomingIrcMessageEvent $event): void
    {
        $message = $event->message;
        $trailing = $message->trailing;
        $raw = $message->toRawLine();

        if (in_array($message->command, ['PRIVMSG', 'SQUERY'], true) && null !== $trailing) {
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
