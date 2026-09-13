<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionController;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionLock;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbWireCodec;
use App\Irc\Adapter\Runtime\SessionEventPump;
use App\Irc\Adapter\Runtime\SessionEventPumpAwareInterface;
use App\Irc\Domain\Server\ServerLink;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function implode;
use function sprintf;
use function str_starts_with;
use function substr;
use function time;

/**
 * UnrealIRCd UDB 4 server-to-server link protocol.
 *
 * The adapter owns its complete handshake and runtime wire behavior. It does
 * not inherit from or delegate to the standalone Unreal adapter, allowing the
 * UDB protocol to evolve independently.
 *
 * HEL semantics (current UDB 4 source of truth): services always announce
 * themselves as the propagator ("HEL 4 <services FQDN>"); when the IRCd's
 * HEL selects the services FQDN (or asks with "?"), services offer the
 * authoritative six-block snapshot and serve divergent blocks.
 */
final class UnrealUdbProtocolHandler implements ProtocolHandlerInterface, SessionEventPumpAwareInterface
{
    private const string PROTOCOL_NAME = 'unrealudb';

    /** @var list<string> */
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

    private ?string $remoteServerName = null;

    private ?string $remoteSid = null;

    public function __construct(
        private readonly string $sid,
        private readonly UdbSessionController $coordinator,
        private readonly UdbSessionLock $lock = new UdbSessionLock(''),
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {}

    public function getProtocolName(): string
    {
        return self::PROTOCOL_NAME;
    }

    public function getSid(): string
    {
        return $this->sid;
    }

    public function getSupportedCapabilities(): array
    {
        return self::CAPABILITIES;
    }

    public function resetRemoteIdentity(): void
    {
        $this->remoteSid = null;
        $this->remoteServerName = null;
    }

    public function parseRawLine(string $rawLine): IRCMessage
    {
        return IRCMessage::fromRawLine($rawLine);
    }

    public function formatMessage(IRCMessage $message): string
    {
        return $message->toRawLine();
    }

    public function setEventPump(?SessionEventPump $eventPump): void
    {
        $this->coordinator->setEventPump($eventPump);
    }

    /** The daemon holds the UDB directory lock for the whole link lifetime. */
    public function performHandshake(ConnectionInterface $connection, ServerLink $link): void
    {
        // A fresh transport must never inherit the direct-peer identity from
        // the previous one, even if a caller reconnects without first
        // dispatching ConnectionLostEvent.
        $this->resetRemoteIdentity();
        $this->lock->acquire();
        try {
            $this->sendHandshake($connection, $link);
            $this->coordinator->setOwnName((string) $link->serverName);
        } catch (Throwable $e) {
            $this->lock->release();
            $this->coordinator->reset();
            $this->resetRemoteIdentity();
            throw $e;
        }
    }

    public function handleIncoming(IRCMessage $message, ConnectionInterface $connection): void
    {
        if ('ERROR' === $message->command) {
            $reason = $message->trailing ?? ($message->params[0] ?? 'unknown');
            $this->logger->critical('Remote server sent ERROR — closing link.', [
                'reason' => $reason,
            ]);
        }

        if ('PING' === $message->command) {
            $target = $message->trailing ?? ($message->params[0] ?? '');
            $pong = 'PONG :' . $target;
            $connection->writeLine($pong);
            $this->logger->debug('> ' . $pong);
        }

        $this->coordinator->tick($connection);

        match ($message->command) {
            'EOS' => $this->handleEosThenReady($connection),
            'NETINFO' => $this->handleNetinfo($message, $connection),
            'PROTOCTL' => $this->handleProtoServerSid($message),
            'SERVER' => $this->handleRemoteServer($message),
            'DB' => $this->handleDb($message, $connection),
            default => null,
        };
    }

    private function sendHandshake(ConnectionInterface $connection, ServerLink $link): void
    {
        $this->logger->debug('Starting UnrealUdb handshake.', [
            'server' => (string) $link->serverName,
            'sid' => $this->sid,
        ]);

        $connection->writeLine(sprintf('PASS :%s', $link->password));
        $this->logger->debug('> PASS :<redacted>');

        $eauth = sprintf('PROTOCTL EAUTH=%s SID=%s', $link->serverName, $this->sid);
        $connection->writeLine($eauth);
        $this->logger->debug('> ' . $eauth);

        $capabilities = sprintf('PROTOCTL %s', implode(' ', self::CAPABILITIES));
        $connection->writeLine($capabilities);
        $this->logger->debug('> ' . $capabilities);

        $server = sprintf('SERVER %s 1 :%s', $link->serverName, $link->description);
        $connection->writeLine($server);
        $this->logger->debug('> ' . $server);

        $this->logger->info('UnrealUdb handshake sent.', [
            'server' => (string) $link->serverName,
            'caps' => self::CAPABILITIES,
        ]);
    }

    private function handleEosThenReady(ConnectionInterface $connection): void
    {
        $this->eventDispatcher?->dispatch(new NetworkBurstCompleteEvent($connection, $this->sid));

        $eos = sprintf(':%s EOS', $this->sid);
        $connection->writeLine($eos);
        $this->logger->debug('> ' . $eos);
        $this->logger->info('Sent EOS — initial burst and sync complete.', ['sid' => $this->sid]);

        $this->coordinator->onLinkReady($connection);
    }

    private function handleNetinfo(IRCMessage $message, ConnectionInterface $connection): void
    {
        $networkName = $message->trailing ?? 'IRC Network';
        $netinfo = sprintf('NETINFO 0 %d 6100 * 0 0 0 :%s', time(), $networkName);

        $connection->writeLine($netinfo);
        $this->logger->debug('> ' . $netinfo);
    }

    /**
     * Captures the remote SID from PROTOCTL (the direct SERVER introduction
     * line carries no prefix, so PROTOCTL SID= is the only source on links
     * where the SERVER line arrives before any prefixed frame).
     */
    private function handleProtoServerSid(IRCMessage $message): void
    {
        foreach ($message->params as $param) {
            if (str_starts_with($param, 'SID=')) {
                $sid = substr($param, 4);
                if ('' !== $sid) {
                    $this->remoteSid = $sid;
                }

                break;
            }
        }

        $this->notifyRemoteIdentity();
    }

    private function handleRemoteServer(IRCMessage $message): void
    {
        $remoteName = $message->params[0] ?? '';
        if ('' === $remoteName) {
            return;
        }

        $this->remoteServerName = $remoteName;

        // Direct peer introductions are unprefixed; fall back to the SID
        // already captured from PROTOCTL SID=.
        $this->remoteSid ??= $message->prefix ?? '';

        $this->notifyRemoteIdentity();
    }

    /** Forwards the captured identity to the coordinator once both parts are known. */
    private function notifyRemoteIdentity(): void
    {
        if (null === $this->remoteSid || null === $this->remoteServerName || '' === $this->remoteSid || '' === $this->remoteServerName) {
            return;
        }

        $this->coordinator->onRemoteServer($this->remoteSid, $this->remoteServerName);
        $this->logger->debug('Captured remote server identity.', [
            'remote' => $this->remoteServerName,
            'sid' => $this->remoteSid,
        ]);
    }

    private function handleDb(IRCMessage $message, ConnectionInterface $connection): void
    {
        $frame = UdbWireCodec::parse($message);
        if (null === $frame) {
            // Malformed INS/PUT input is untrusted and may still contain
            // secrets, so never mirror its params into application logs.
            $this->logger->debug('Ignored malformed or unsupported UDB DB frame.', [
                'prefix' => $message->prefix,
            ]);

            return;
        }

        $this->coordinator->handleFrame($frame, $connection);
    }
}
