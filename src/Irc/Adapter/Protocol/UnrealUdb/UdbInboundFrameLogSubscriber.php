<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Event\IncomingIrcMessageEvent;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbWireCodec;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbWireLogRedactor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function strtoupper;

/**
 * Logs inbound UDB DB frames with secret record values redacted.
 *
 * IncomingIrcMessageEvent is a generic notification shared by every protocol,
 * so the generic logger must stay protocol-agnostic. This subscriber owns DB
 * frame logging for the UDB adapter: it runs before IRCEventSubscriber and
 * stops propagation for DB frames so they are never mirrored raw. Malformed
 * frames are logged by the protocol handler without their payload.
 */
final readonly class UdbInboundFrameLogSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /** Runs before IRCEventSubscriber (priority 0): DB logging is exclusive here. */
    public static function getSubscribedEvents(): array
    {
        return [IncomingIrcMessageEvent::class => ['onIncomingMessage', 10]];
    }

    public function onIncomingMessage(IncomingIrcMessageEvent $event): void
    {
        $message = $event->message;
        if ('DB' !== strtoupper($message->command)) {
            return;
        }

        $event->stopPropagation();

        $frame = UdbWireCodec::parse($message);
        if (null === $frame) {
            return;
        }

        // Valid frames stay observable; the redactor masks N::pass and
        // S::encryption_key values before the line reaches the log.
        $this->logger->debug('< ' . $message->command, [
            'raw' => UdbWireLogRedactor::redactFrame($frame, $message->toRawLine()),
        ]);
    }
}
