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
    private const PROTOCOL_NAME = 'unreal';

    /**
     * PROTOCTL capability tokens supported by Ares.
     * Reference: https://www.unrealircd.org/docs/Server_protocol:PROTOCTL_command
     *
     * UMODE2 is required so that UnrealIRCd 6 propagates user-mode changes
     * (including the +r mode set by SVSLOGIN) back to us via UMODE2 messages.
     */
    private const CAPABILITIES = [
        'NOQUIT',    // Suppress QUIT for each user on netsplit
        'NICKv2',    // Extended NICK command
        'SJOIN',     // Channel sync via SJOIN
        'SJOIN2',    // SJOIN v2 extension
        'SJ3',       // SJOIN extension
        'CLK',       // Clock / timestamp support  ← required in 4.x+
        'TKLEXT',    // Extended TKL (ban) support
        'TKLEXT2',   // Extended TKL v2            ← required in 4.x+
        'NICKIP',    // Include IP in NICK
        'ESVID',     // Extended SVS commands (SVSLOGIN)
        'UMODE2',    // User-mode change propagation via S2S (required for SVSLOGIN +r)
        'MLOCK',     // Channel mode-lock
        'EXTSWHOIS', // Extended /WHOIS lines
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
            'sid'    => $this->sid,
        ]);

        // Step 1 — link password (not logged to avoid leaking credentials)
        $connection->writeLine(sprintf('PASS :%s', $link->password));

        // Step 2 — EAUTH + SID: this is what tells UnrealIRCd we speak the 4.x+ protocol.
        //          Sending PROTOCTL without EAUTH causes the LINK_OLD_PROTOCOL rejection.
        $eauth = sprintf('PROTOCTL EAUTH=%s SID=%s', $link->serverName, $this->sid);
        $connection->writeLine($eauth);
        $this->logger->debug('> ' . $eauth);

        // Step 3 — advertise supported capabilities
        $caps = sprintf('PROTOCTL %s', implode(' ', self::CAPABILITIES));
        $connection->writeLine($caps);
        $this->logger->debug('> ' . $caps);

        // Step 4 — introduce our server
        $server = sprintf('SERVER %s 1 :%s', $link->serverName, $link->description);
        $connection->writeLine($server);
        $this->logger->debug('> ' . $server);

        $this->logger->info('UnrealIRCd handshake sent.', [
            'server' => (string) $link->serverName,
            'caps'   => self::CAPABILITIES,
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
            'EOS'     => $this->handleEos($connection),
            'NETINFO' => $this->handleNetinfo($message, $connection),
            default   => null,
        };
    }

    private function handleEos(ConnectionInterface $connection): void
    {
        // Allow service bots to introduce their pseudo-clients before our EOS.
        $this->dispatchBurstComplete($connection, $this->sid);

        $eos = sprintf(':%s EOS', $this->sid);
        $connection->writeLine($eos);
        $this->logger->info('Sent EOS — initial burst and sync complete.', ['sid' => $this->sid]);
    }

    private function handleNetinfo(IRCMessage $message, ConnectionInterface $connection): void
    {
        // Mirror back a minimal NETINFO so UnrealIRCd accepts the link state.
        // Fields: <max_global> <timestamp> <protocol_version> <cloak_hash> 0 0 0 :<network_name>
        $networkName = $message->trailing ?? 'IRC Network';
        $netinfo     = sprintf('NETINFO 0 %d 6100 * 0 0 0 :%s', time(), $networkName);

        $connection->writeLine($netinfo);
        $this->logger->debug('> ' . $netinfo);
    }
}
