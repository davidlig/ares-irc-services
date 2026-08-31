<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Server\ServerLink;
use App\Infrastructure\IRC\Protocol\AbstractProtocolHandler;
use App\Infrastructure\IRC\Protocol\UnrealFamily\UnrealFamilyHandshakeTrait;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbWireCodec;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function str_starts_with;
use function substr;

/**
 * UnrealIRCd UDB 4 server-to-server link protocol.
 *
 * The base handshake (PASS/PROTOCTL/SERVER, NETINFO, EOS) is shared with the
 * Unreal module via UnrealFamilyHandshakeTrait. Everything UDB-specific —
 * HEL 4 negotiation, reconciliation rounds, staged snapshots, mutations —
 * is delegated to UdbSessionCoordinator; this class only parses wire lines
 * and forwards them.
 *
 * HEL semantics (current UDB 4 source of truth): services always announce
 * themselves as the propagator ("HEL 4 <services FQDN>"); when the IRCd's
 * HEL selects the services FQDN (or asks with "?"), services offer the
 * authoritative six-block snapshot and serve divergent blocks.
 */
final class UnrealUdbProtocolHandler extends AbstractProtocolHandler
{
    use UnrealFamilyHandshakeTrait;

    private const string PROTOCOL_NAME = 'unrealudb';

    private ?string $remoteServerName = null;

    private ?string $remoteSid = null;

    public function __construct(
        private readonly string $sid,
        private readonly UdbSessionCoordinator $coordinator,
        LoggerInterface $logger = new NullLogger(),
        ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        parent::__construct($logger, $eventDispatcher);
    }

    public function getProtocolName(): string
    {
        return self::PROTOCOL_NAME;
    }

    public function handleIncoming(IRCMessage $message, ConnectionInterface $connection): void
    {
        parent::handleIncoming($message, $connection);
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

    /** Captures the services FQDN announced in our own SERVER line. */
    protected function onLinkEstablished(ServerLink $link): void
    {
        $this->coordinator->setOwnName((string) $link->serverName);
    }

    /** Trait EOS handling (burst complete + our EOS), then HEL negotiation starts. */
    private function handleEosThenReady(ConnectionInterface $connection): void
    {
        $this->handleEos($connection);
        $this->coordinator->onLinkReady($connection);
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
            $this->logger->debug('Ignored malformed or unsupported UDB DB frame.', [
                'prefix' => $message->prefix,
                'params' => $message->params,
            ]);

            return;
        }

        $this->coordinator->handleFrame($frame, $connection);
    }
}
