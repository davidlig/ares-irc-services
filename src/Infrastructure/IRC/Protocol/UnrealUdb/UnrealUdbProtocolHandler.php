<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Message\IRCMessage;
use App\Infrastructure\IRC\Protocol\AbstractProtocolHandler;
use App\Infrastructure\IRC\Protocol\UnrealFamily\UnrealFamilyHandshakeTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_slice;
use function count;
use function implode;
use function sprintf;

/**
 * Implements the UnrealUdb 4.x / 5.x / 6.x server-to-server link protocol.
 *
 * The base handshake (PASS/PROTOCTL/SERVER, NETINFO, EOS, capabilities) is
 * shared with the Unreal module via UnrealFamilyHandshakeTrait. This module
 * additionally negotiates the UDB HEL 4 capability and speaks the DB command
 * (INF/INS/DEL/DRP/OPT/FDR and the staged BEGIN/PUT/END/ACK transaction).
 *
 * HEL handshake (per doc/udb_technical_en.md):
 *   1. The IRCd sends ":<sid> DB <peer> HEL 4 <selected-propagator>".
 *   2. We reply ":<sid> DB <peer> HEL 4 ACK" and send our own HEL announcing the
 *      IRCd's server name (captured from its SERVER introduction line) as the
 *      selected propagator, so the IRCd authorizes outbound snapshots
 *      (authorizes_us). Announcing our own name would leave authorizes_us
 *      false and every RES would be rejected with UDB_ERR_FORBIDDEN.
 *   3. Only after ACK does UDB send INF and accept our RES/INS/DEL requests.
 *   Our HEL is deferred until the remote server name is known.
 */
class UnrealUdbProtocolHandler extends AbstractProtocolHandler
{
    use UnrealFamilyHandshakeTrait;

    private const string PROTOCOL_NAME = 'unrealudb';

    private ?string $remoteServerName = null;

    private ?string $remoteSid = null;

    private bool $helSent = false;

    /** @var array<string, string> */
    private array $stagedTxids = [];

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

    /**
     * Handles UnrealUdb-specific incoming commands on top of the base PING/PONG.
     *
     * EOS (End of Sync): the IRCd sends EOS when it finishes its burst. We must
     * respond with our own EOS so UnrealUdb knows we are ready. Failing to send
     * EOS causes an immediate clean disconnect ("Success" error code).
     */
    public function handleIncoming(IRCMessage $message, ConnectionInterface $connection): void
    {
        parent::handleIncoming($message, $connection);

        match ($message->command) {
            'EOS' => $this->handleEos($connection),
            'NETINFO' => $this->handleNetinfo($message, $connection),
            'SERVER' => $this->handleRemoteServer($message, $connection),
            'DB' => $this->handleDb($message, $connection),
            default => null,
        };
    }

    private function handleDb(IRCMessage $message, ConnectionInterface $connection): void
    {
        if (count($message->params) < 2) {
            return;
        }

        $operation = $message->params[1];
        $sourceSid = $message->prefix ?? '';

        if ('' !== $sourceSid) {
            $this->remoteSid = $sourceSid;
        }

        if ('HEL' === $operation) {
            $this->handleHel($message, $connection);

            return;
        }

        if ('ERR' === $operation) {
            $this->logger->warning('UDB reported an error.', [
                'subcommand' => $message->params[2] ?? '?',
                'code' => $message->params[3] ?? '?',
                'extra' => $message->params[4] ?? $message->trailing ?? '',
                'source' => $sourceSid,
            ]);

            return;
        }

        if ('BEGIN' === $operation) {
            $this->handleBegin($message);

            return;
        }

        if ('PUT' === $operation) {
            $this->handlePut($message);

            return;
        }

        if ('END' === $operation) {
            $this->handleEnd($message, $connection);

            return;
        }

        if ('ACK' === $operation) {
            $this->logger->info(sprintf('UDB staged sync acknowledged for block %s', $message->params[2] ?? '?'));

            return;
        }

        if ('RES' === $operation) {
            // UDB asks us to serve a snapshot; we hold no UDB records of our own.
            $this->logger->debug(sprintf('UDB requested block %s from us; ignoring (no local UDB data).', $message->params[2] ?? '?'));

            return;
        }

        if ('INF' === $operation) {
            $block = $message->params[2] ?? '';
            if (null !== $this->eventDispatcher) {
                $this->eventDispatcher->dispatch(new Event\UdbSyncRequestedEvent($block, $sourceSid));
            }

            return;
        }

        if ('INS' === $operation) {
            $key = $message->params[2] ?? '';
            // Fix: multi-word values (e.g. swhois, topic, forbid) are NOT prefixed with ':'
            // so they arrive as extra params instead of trailing. Use trailing when available,
            // otherwise join all remaining params (index 3+) into a single space-separated string.
            $value = $message->trailing ?? implode(' ', array_slice($message->params, 3));
            if (null !== $this->eventDispatcher) {
                $this->eventDispatcher->dispatch(new Event\UdbRecordReceivedEvent($key, $value));
            }

            return;
        }

        if ('DEL' === $operation) {
            $key = $message->params[2] ?? '';
            if (null !== $this->eventDispatcher) {
                $this->eventDispatcher->dispatch(new Event\UdbRecordDeletedEvent($key));
            }

            return;
        }

        if ('FDR' === $operation) {
            $block = $message->params[2] ?? '';
            $this->logger->info(sprintf('UDB sync completed (FDR) for block %s', $block));
            if (null !== $this->eventDispatcher) {
                $this->eventDispatcher->dispatch(new Event\UdbSyncCompleteEvent($block, $sourceSid));
            }
        }
    }

    /**
     * HEL 4 capability negotiation: ACK the peer's HEL and send our own HEL so
     * the peer confirms UDB capability on its side and authorizes us to request
     * block snapshots (RES). Our HEL is deferred until the remote server name
     * is known; announcing our own name would leave authorizes_us false.
     */
    private function handleHel(IRCMessage $message, ConnectionInterface $connection): void
    {
        $sourceSid = $message->prefix ?? '';
        if ('' === $sourceSid) {
            $this->logger->warning('UDB HEL received without a source SID; ignoring.');

            return;
        }

        if (count($message->params) >= 4 && 'ACK' === $message->params[3]) {
            $this->logger->info('UDB HEL 4 capability confirmed by peer.', ['peer' => $sourceSid]);

            return;
        }

        $this->logger->info('UDB HEL 4 received from peer; acknowledging.', ['peer' => $sourceSid]);

        $ack = sprintf(':%s DB %s HEL 4 ACK', $this->sid, $sourceSid);
        $connection->writeLine($ack);
        $this->logger->debug('> ' . $ack);

        if (null === $this->remoteServerName) {
            $this->logger->debug('Remote server name not yet known; deferring our HEL until the SERVER line arrives.');

            return;
        }

        $this->sendHel($connection, $sourceSid);
    }

    /**
     * Captures the remote server's name from its SERVER introduction line
     * (e.g. "SERVER irc.davidlig.net 1 :U6-..."). The IRCd sends this right
     * after accepting our own SERVER line. If a HEL exchange already happened,
     * the deferred HEL is sent now that the name is known.
     */
    private function handleRemoteServer(IRCMessage $message, ConnectionInterface $connection): void
    {
        $remoteName = $message->params[0] ?? '';
        if ('' === $remoteName) {
            return;
        }

        $this->remoteServerName = $remoteName;
        $this->logger->debug('Captured remote server name from SERVER line.', ['remote' => $remoteName]);

        if (!$this->helSent && null !== $this->remoteSid) {
            $this->sendHel($connection, $this->remoteSid);
        }
    }

    /**
     * Sends our own HEL 4 request selecting the peer as propagator, so the peer
     * authorizes outbound snapshots (staged BEGIN/PUT/END) for our RES requests.
     * Only sent once the remote server name is known; announcing a wrong name
     * (e.g. our own) leaves authorizes_us false and RES gets UDB_ERR_FORBIDDEN.
     */
    private function sendHel(ConnectionInterface $connection, ?string $peerSid): void
    {
        if ($this->helSent || null === $peerSid || '' === $peerSid || null === $this->remoteServerName) {
            return;
        }

        $hel = sprintf(':%s DB %s HEL 4 %s', $this->sid, $peerSid, $this->remoteServerName);
        $connection->writeLine($hel);
        $this->logger->debug('> ' . $hel);
        $this->helSent = true;
    }

    private function handleBegin(IRCMessage $message): void
    {
        $block = $message->params[2] ?? '';
        $txid = $message->params[3] ?? '';

        if ('' === $block || '' === $txid) {
            return;
        }

        $this->stagedTxids[$block] = $txid;
        $this->logger->info(sprintf('UDB staged sync started for block %s (txid %s)', $block, $txid));
    }

    private function handlePut(IRCMessage $message): void
    {
        $block = $message->params[2] ?? '';
        $txid = $message->params[3] ?? '';
        $path = $message->params[4] ?? '';

        if (($this->stagedTxids[$block] ?? null) !== $txid || '' === $path) {
            return;
        }

        $value = $message->trailing ?? implode(' ', array_slice($message->params, 5));
        if (null !== $this->eventDispatcher) {
            // PUT paths omit the block prefix; the block is an explicit parameter.
            $this->eventDispatcher->dispatch(new Event\UdbRecordReceivedEvent(sprintf('%s::%s', $block, $path), $value));
        }
    }

    private function handleEnd(IRCMessage $message, ConnectionInterface $connection): void
    {
        $block = $message->params[2] ?? '';
        $txid = $message->params[3] ?? '';
        $digest = $message->params[4] ?? $message->trailing ?? '';

        if (($this->stagedTxids[$block] ?? null) !== $txid) {
            return;
        }

        unset($this->stagedTxids[$block]);
        $this->logger->info(sprintf('UDB staged sync completed for block %s', $block));

        $sourceSid = $message->prefix ?? '';
        if ('' !== $sourceSid && '' !== $digest) {
            $ack = sprintf(':%s DB %s ACK %s %s %s', $this->sid, $sourceSid, $block, $txid, $digest);
            $connection->writeLine($ack);
            $this->logger->debug('> ' . $ack);
        }

        if (null !== $this->eventDispatcher) {
            $this->eventDispatcher->dispatch(new Event\UdbSyncCompleteEvent($block, $sourceSid));
        }
    }
}
