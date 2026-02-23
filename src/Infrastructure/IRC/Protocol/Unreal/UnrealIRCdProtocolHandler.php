<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\Unreal;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Server\ServerLink;
use App\Infrastructure\IRC\Protocol\AbstractProtocolHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function sprintf;

/**
 * Implements the UnrealIRCd 4.x / 5.x / 6.x server-to-server link protocol.
 *
 * Handshake sequence (per https://www.unrealircd.org/docs/Server_protocol:Introduction):
 *   1. PASS :<password>
 *   2. PROTOCTL EAUTH=<server_name> SID=<sid>   ← identifies the new protocol; without this
 *                                                   UnrealIRCd treats the link as 3.2.x and rejects it
 *   3. PROTOCTL <capabilities>
 *   4. SERVER <name> 1 :<description>
 *
 * After the IRCD burst completes it sends EOS. We must respond with our own EOS
 * to mark that we have finished syncing. Failure to do so causes an immediate
 * clean disconnect ("Success" error code).
 *
 * The SID must be a unique 3-digit numeric assigned to this server on the network.
 * See https://www.unrealircd.org/docs/Server_protocol:Server_ID
 */
class UnrealIRCdProtocolHandler extends AbstractProtocolHandler
{
    private const string PROTOCOL_NAME = 'unreal';

    /**
     * PROTOCTL capability tokens announced to UnrealIRCd.
     * Reference: https://www.unrealircd.org/docs/Server_protocol:PROTOCTL_command.
     *
     * Aligned with Anope's capability set for maximum compatibility.
     */
    private const array CAPABILITIES = [
        'NOQUIT',
        'NICKv2',
        'SJOIN',
        'SJOIN2',
        'SJ3',
        'CLK',
        'TKLEXT',
        'TKLEXT2',
        'NICKIP',
        'ESVID',
        'UMODE2',
        'MLOCK',
        'EXTSWHOIS',
        'VHP',
        'BIGLINES',
        'MTAGS',
        'NEXTBANS',
        'SJSBY',
    ];

    public function __construct(
        private readonly string $sid = '001',
        LoggerInterface $logger = new NullLogger(),
        ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        parent::__construct($logger, $eventDispatcher);
    }

    public function getProtocolName(): string
    {
        return self::PROTOCOL_NAME;
    }

    public function getSupportedCapabilities(): array
    {
        return self::CAPABILITIES;
    }

    public function performHandshake(ConnectionInterface $connection, ServerLink $link): void
    {
        $this->logger->debug('Starting UnrealIRCd handshake.', [
            'server' => (string) $link->serverName,
            'sid' => $this->sid,
        ]);

        $connection->writeLine(sprintf('PASS :%s', $link->password));

        $eauth = sprintf('PROTOCTL EAUTH=%s SID=%s', $link->serverName, $this->sid);
        $connection->writeLine($eauth);
        $this->logger->debug('> ' . $eauth);

        $caps = sprintf('PROTOCTL %s', implode(' ', self::CAPABILITIES));
        $connection->writeLine($caps);
        $this->logger->debug('> ' . $caps);

        $server = sprintf('SERVER %s 1 :%s', $link->serverName, $link->description);
        $connection->writeLine($server);
        $this->logger->debug('> ' . $server);

        $this->logger->info('UnrealIRCd handshake sent.', [
            'server' => (string) $link->serverName,
            'caps' => self::CAPABILITIES,
        ]);
    }

    /**
     * Handles UnrealIRCd-specific incoming commands on top of the base PING/PONG.
     *
     * EOS (End of Sync): the IRCD sends EOS when it finishes its burst. We must
     * respond with our own EOS so UnrealIRCd knows we are ready. Failing to send
     * EOS causes an immediate clean disconnect ("Success" error code).
     */
    public function handleIncoming(IRCMessage $message, ConnectionInterface $connection): void
    {
        parent::handleIncoming($message, $connection);

        match ($message->command) {
            'EOS' => $this->handleEos($connection),
            'NETINFO' => $this->handleNetinfo($message, $connection),
            default => null,
        };
    }

    private function handleEos(ConnectionInterface $connection): void
    {
        $this->dispatchBurstComplete($connection, $this->sid);

        $eos = sprintf(':%s EOS', $this->sid);
        $connection->writeLine($eos);
        $this->logger->info('Sent EOS — initial burst and sync complete.', ['sid' => $this->sid]);
    }

    private function handleNetinfo(IRCMessage $message, ConnectionInterface $connection): void
    {
        $networkName = $message->trailing ?? 'IRC Network';
        $netinfo = sprintf('NETINFO 0 %d 6100 * 0 0 0 :%s', time(), $networkName);

        $connection->writeLine($netinfo);
        $this->logger->debug('> ' . $netinfo);
    }
}
