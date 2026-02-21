<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Logging;

use App\Domain\IRC\Event\ConnectionEstablishedEvent;
use App\Domain\IRC\Event\ConnectionLostEvent;
use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Infrastructure\IRC\Security\SensitiveDataRedactor;
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
class IRCEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConnectionEstablishedEvent::class => 'onConnectionEstablished',
            ConnectionLostEvent::class        => 'onConnectionLost',
            MessageReceivedEvent::class       => 'onMessageReceived',
        ];
    }

    public function onConnectionEstablished(ConnectionEstablishedEvent $event): void
    {
        $this->logger->info('Server link established.', [
            'server'   => (string) $event->serverLink->serverName,
            'host'     => (string) $event->serverLink->host,
            'port'     => $event->serverLink->port->value,
            'tls'      => $event->serverLink->useTls,
            'occurred' => $event->occurredAt->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function onConnectionLost(ConnectionLostEvent $event): void
    {
        $this->logger->warning('Server link lost.', [
            'server'   => (string) $event->serverLink->serverName,
            'reason'   => $event->reason ?? 'unknown',
            'occurred' => $event->occurredAt->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function onMessageReceived(MessageReceivedEvent $event): void
    {
        $message  = $event->message;
        $trailing = $message->trailing;
        $raw      = $message->toRawLine();

        // Redact passwords in PRIVMSG content targeting NickServ.
        if ('PRIVMSG' === $message->command && $trailing !== null) {
            $redacted = SensitiveDataRedactor::redactNickServCommand($trailing);
            if ($redacted !== $trailing) {
                $trailing = $redacted;
                $raw      = preg_replace('/(\s:).*$/', '$1' . $redacted, $raw) ?? $raw;
            }
        }

        $this->logger->debug('< ' . $message->command, [
            'prefix'   => $message->prefix,
            'params'   => $message->params,
            'trailing' => $trailing,
            'raw'      => $raw,
        ]);
    }
}
