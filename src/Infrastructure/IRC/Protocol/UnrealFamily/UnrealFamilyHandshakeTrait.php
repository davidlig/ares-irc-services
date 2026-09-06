<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealFamily;

use App\Domain\IRC\Server\ServerLink;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\IRCMessage;

use function implode;
use function sprintf;
use function time;

/**
 * Shared, stable Unreal-family S2S handshake (UnrealIRCd 4.x/5.x/6.x).
 *
 * This trait is intentionally frozen: it contains only the wire primitives
 * that are identical across every Unreal-family protocol module (handshake,
 * NETINFO, EOS, capabilities). Protocol-specific logic (DB/HEL for UnrealUdb,
 * mode support, nick reservation, service actions) lives in each module so
 * that removing or changing one protocol never affects the other.
 *
 * Subclasses may override onLinkEstablished() to capture the link parameters.
 */
trait UnrealFamilyHandshakeTrait
{
    /**
     * PROTOCTL capability tokens announced to UnrealIRCd.
     * Reference: https://www.unrealircd.org/docs/Server_protocol:PROTOCTL_command.
     *
     * Standard capability set for maximum IRCd compatibility.
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

    public function getSupportedCapabilities(): array
    {
        return self::CAPABILITIES;
    }

    /**
     * Handshake sequence (per https://www.unrealircd.org/docs/Server_protocol:Introduction):
     *   1. PASS :<password>
     *   2. PROTOCTL EAUTH=<server_name> SID=<sid>   ← identifies the new protocol; without this
     *                                                   UnrealIRCd treats the link as 3.2.x and rejects it
     *   3. PROTOCTL <capabilities>
     *   4. SERVER <name> 1 :<description>
     */
    public function performHandshake(ConnectionInterface $connection, ServerLink $link): void
    {
        $this->logger->debug('Starting Unreal-family handshake.', [
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

        $this->logger->info('Unreal-family handshake sent.', [
            'server' => (string) $link->serverName,
            'caps' => self::CAPABILITIES,
        ]);

        $this->onLinkEstablished($link);
    }

    /**
     * Hook for subclasses to capture link parameters (e.g. the remote server
     * name needed by the UnrealUdb HEL negotiation).
     */
    protected function onLinkEstablished(ServerLink $link): void {}

    /**
     * EOS (End of Sync): the IRCd sends EOS when it finishes its burst. We must
     * respond with our own EOS so UnrealIRCd knows we are ready. Failing to send
     * EOS causes an immediate clean disconnect ("Success" error code).
     */
    protected function handleEos(ConnectionInterface $connection): void
    {
        $this->dispatchBurstComplete($connection, $this->sid);

        $eos = sprintf(':%s EOS', $this->sid);
        $connection->writeLine($eos);
        $this->logger->info('Sent EOS — initial burst and sync complete.', ['sid' => $this->sid]);
        // NetworkSyncCompleteEvent is dispatched by SyncCompleteDispatcherSubscriber after MessageReceivedEvent(EOS)
    }

    protected function handleNetinfo(IRCMessage $message, ConnectionInterface $connection): void
    {
        $networkName = $message->trailing ?? 'IRC Network';
        $netinfo = sprintf('NETINFO 0 %d 6100 * 0 0 0 :%s', time(), $networkName);

        $connection->writeLine($netinfo);
        $this->logger->debug('> ' . $netinfo);
    }
}
