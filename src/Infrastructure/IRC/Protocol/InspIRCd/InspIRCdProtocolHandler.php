<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\InspIRCd;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Server\ServerLink;
use App\Infrastructure\IRC\Protocol\AbstractProtocolHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function sprintf;

/**
 * Implements the InspIRCd SpanTree server-to-server link protocol (v1.2+).
 *
 * Handshake sequence:
 *   1. SERVER <name> <password> <hopcount> <SID> :<description>
 *
 * After the IRCD burst completes it sends ENDBURST. We must respond with
 * our own ENDBURST to mark that we have finished syncing.
 *
 * The SID is a 3-character alphanumeric server identifier unique on the network.
 */
class InspIRCdProtocolHandler extends AbstractProtocolHandler
{
    private const string PROTOCOL_NAME = 'inspircd';

    public function __construct(
        private readonly string $sid = 'A0A',
        LoggerInterface $logger = new NullLogger(),
        ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        parent::__construct($logger, $eventDispatcher);
    }

    public function getProtocolName(): string
    {
        return self::PROTOCOL_NAME;
    }

    public function performHandshake(ConnectionInterface $connection, ServerLink $link): void
    {
        $this->logger->debug('Starting InspIRCd handshake.', [
            'server' => (string) $link->serverName,
            'sid' => $this->sid,
        ]);

        $connection->writeLine(sprintf(
            'SERVER %s %s 0 %s :%s',
            $link->serverName,
            $link->password,
            $this->sid,
            $link->description,
        ));

        $this->logger->debug(sprintf(
            '> SERVER %s *** 0 %s :%s',
            $link->serverName,
            $this->sid,
            $link->description,
        ));

        $this->logger->info('InspIRCd handshake sent.', [
            'server' => (string) $link->serverName,
            'sid' => $this->sid,
        ]);
    }

    /**
     * Handles InspIRCd-specific incoming commands on top of the base PING/PONG.
     *
     * ENDBURST: the InspIRCd equivalent of EOS. We must respond with our own
     * ENDBURST so InspIRCd knows we are ready after the initial sync.
     */
    public function handleIncoming(IRCMessage $message, ConnectionInterface $connection): void
    {
        parent::handleIncoming($message, $connection);

        if ('ENDBURST' === $message->command) {
            $this->dispatchBurstComplete($connection, $this->sid);

            $endburst = sprintf(':%s ENDBURST', $this->sid);
            $connection->writeLine($endburst);
            $this->logger->info('Sent ENDBURST — initial burst and sync complete.', ['sid' => $this->sid]);
        }
    }
}
