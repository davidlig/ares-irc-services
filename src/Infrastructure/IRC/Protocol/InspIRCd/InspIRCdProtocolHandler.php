<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\InspIRCd;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Server\ServerLink;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Protocol\AbstractProtocolHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function sprintf;

/**
 * Implements the InspIRCd SpanTree server-to-server link protocol (v4 / 1206).
 *
 * Handshake sequence (outbound, protocol 1206):
 *   1. CAPAB START 1206
 *   2. CAPAB CAPABILITIES :CASEMAPPING=ascii
 *   3. CAPAB END
 *   4. SERVER <name> <password> <SID> :<description>
 *
 * When the remote IRCD accepts our credentials it sends its own SERVER line
 * back and enters WAIT_AUTH_2. We MUST send BURST + introductions + ENDBURST
 * at that point — InspIRCd will not send its netburst until it receives our
 * BURST (see treesocket2.cpp: WAIT_AUTH_2 expects BURST command).
 *
 * After we send ENDBURST, InspIRCd finishes authentication (FinishAuth),
 * sends its own netburst (DoBurst) and finally ENDBURST. We process the
 * remote burst and, on ENDBURST, dispatch NetworkSyncCompleteEvent so
 * post-sync actions can run (e.g. ChanServ rejoining channels).
 *
 * Incoming CAPAB lines (CHANMODES, MODSUPPORT, USERMODES, EXTBANS,
 * CAPABILITIES) are accumulated during the remote handshake. When the remote
 * CAPAB END is received, they are parsed into an InspIRCdCapab value object
 * which is used to update InspIRCdChannelModeSupport via the factory.
 * This lets the rest of Ares know which modes the remote IRCd actually
 * supports (e.g. +P permanent, +q/+a/+h prefix ranks).
 *
 * We do NOT send CHALLENGE in CAPAB CAPABILITIES, which tells InspIRCd
 * to use plaintext password authentication (no HMAC-SHA256). If both
 * sides send a CHALLENGE, InspIRCd switches to HMAC-SHA256 auth.
 *
 * The SID is a 3-character alphanumeric server identifier unique on the network.
 * Protocol 1206 (v4) does NOT include a hop-count before the SID (unlike 1205/v3).
 */
class InspIRCdProtocolHandler extends AbstractProtocolHandler
{
    private const string PROTOCOL_NAME = 'inspircd';

    private const int PROTOCOL_VERSION = 1206;

    private bool $outgoingBurstSent = false;

    /** @var list<string> Accumulated raw CAPAB lines from the remote server */
    private array $remoteCapabLines = [];

    private bool $remoteCapabActive = false;

    public function __construct(
        private readonly string $sid = 'A0A',
        private readonly ?ActiveConnectionHolder $connectionHolder = null,
        private readonly ?InspIRCdChannelModeSupportFactory $modeSupportFactory = null,
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
     * InspIRCd v4 sends IRCv3 message tags (e.g. @time=...;msgid=...)
     * on PRIVMSG, OPERTYPE, etc. Strip them before parsing so the rest of
     * the pipeline sees a clean RFC 1459 message.
     */
    public function parseRawLine(string $rawLine): IRCMessage
    {
        if (str_starts_with($rawLine, '@')) {
            $spacePos = strpos($rawLine, ' ');
            if (false !== $spacePos) {
                $rawLine = substr($rawLine, $spacePos + 1);
            }
        }

        return parent::parseRawLine($rawLine);
    }

    public function performHandshake(ConnectionInterface $connection, ServerLink $link): void
    {
        $this->logger->debug('Starting InspIRCd handshake.', [
            'server' => (string) $link->serverName,
            'sid' => $this->sid,
        ]);

        $this->outgoingBurstSent = false;
        $this->remoteCapabLines = [];
        $this->remoteCapabActive = false;
        $this->sendCapabilities($connection);
        $this->sendServerLine($connection, $link);

        $this->logger->info('InspIRCd handshake sent.', [
            'server' => (string) $link->serverName,
            'sid' => $this->sid,
        ]);
    }

    public function handleIncoming(IRCMessage $message, ConnectionInterface $connection): void
    {
        if ('PING' === $message->command) {
            $this->handlePing($message, $connection);

            return;
        }

        if ('ERROR' === $message->command) {
            $reason = $message->trailing ?? ($message->params[0] ?? 'unknown');
            $this->logger->critical('Remote server sent ERROR — closing link.', [
                'reason' => $reason,
            ]);

            return;
        }

        if ('CAPAB' === $message->command) {
            $this->handleCapab($message);

            return;
        }

        match ($message->command) {
            'SERVER' => $this->handleRemoteServer($message, $connection),
            'ENDBURST' => $this->handleEndburst($connection),
            default => null,
        };
    }

    private function handleCapab(IRCMessage $message): void
    {
        $subCommand = strtoupper($message->params[0] ?? '');

        if ('START' === $subCommand) {
            $this->remoteCapabLines = [];
            $this->remoteCapabActive = true;

            return;
        }

        if ('END' === $subCommand) {
            $this->remoteCapabActive = false;
            $this->applyRemoteCapab();

            return;
        }

        if ($this->remoteCapabActive) {
            $this->remoteCapabLines[] = $message->toRawLine();
        }
    }

    private function applyRemoteCapab(): void
    {
        if (null === $this->modeSupportFactory || [] === $this->remoteCapabLines) {
            return;
        }

        $capab = InspIRCdCapab::fromCapabLines($this->remoteCapabLines);
        $newModeSupport = $this->modeSupportFactory->createFromCapab($capab);

        $module = $this->connectionHolder?->getProtocolModule();
        if ($module instanceof InspIRCdModule) {
            $module->updateChannelModeSupport($newModeSupport);
            $this->logger->info('Updated InspIRCd channel mode support from remote CAPAB.', [
                'prefixModes' => $newModeSupport->getSupportedPrefixModes(),
                'hasPermanent' => $newModeSupport->hasPermanentChannelMode(),
                'hasRegistered' => $newModeSupport->hasChannelRegisteredMode(),
            ]);
        }
    }

    private function handlePing(IRCMessage $message, ConnectionInterface $connection): void
    {
        $originSid = $message->prefix ?? null;
        $targetParam = $message->params[0] ?? $message->trailing ?? '';
        $pongTarget = $originSid ?? $targetParam;

        $pong = sprintf(':%s PONG %s', $this->sid, $pongTarget);
        $this->writeLine($connection, $pong);
    }

    private function sendCapabilities(ConnectionInterface $connection): void
    {
        $this->writeLine($connection, sprintf('CAPAB START %d', self::PROTOCOL_VERSION));

        $this->writeLine($connection, 'CAPAB CAPABILITIES :CASEMAPPING=ascii');

        $this->writeLine($connection, 'CAPAB END');
    }

    private function sendServerLine(ConnectionInterface $connection, ServerLink $link): void
    {
        $serverLine = sprintf(
            'SERVER %s %s %s :%s',
            $link->serverName,
            $link->password,
            $this->sid,
            $link->description,
        );
        $this->writeLine($connection, $serverLine);

        $this->logger->debug(sprintf(
            '> SERVER %s *** %s :%s',
            $link->serverName,
            $this->sid,
            $link->description,
        ));
    }

    private function handleRemoteServer(IRCMessage $message, ConnectionInterface $connection): void
    {
        $remoteSid = $message->params[2] ?? null;
        $remoteName = $message->params[0] ?? 'unknown';
        $remoteDescription = $message->trailing ?? '';

        if (null !== $remoteSid) {
            $this->connectionHolder?->setRemoteServerSid($remoteSid);
        }

        $this->logger->info('Remote server introduced itself.', [
            'name' => $remoteName,
            'sid' => $remoteSid,
            'description' => $remoteDescription,
        ]);

        $this->sendOutgoingBurst($connection);
    }

    private function sendOutgoingBurst(ConnectionInterface $connection): void
    {
        if ($this->outgoingBurstSent) {
            return;
        }

        $this->outgoingBurstSent = true;

        $burst = sprintf(':%s BURST %d', $this->sid, time());
        $this->writeLine($connection, $burst);
        $this->logger->info('Sent BURST — beginning service introduction.', ['sid' => $this->sid]);

        $this->dispatchBurstComplete($connection, $this->sid);

        $endburst = sprintf(':%s ENDBURST', $this->sid);
        $this->writeLine($connection, $endburst);
        $this->logger->info('Sent ENDBURST — outgoing burst complete.', ['sid' => $this->sid]);
    }

    private function handleEndburst(ConnectionInterface $connection): void
    {
        $this->logger->info('Received ENDBURST — remote burst complete, network synced.', ['sid' => $this->sid]);

        $this->sendOutgoingBurst($connection);
    }

    private function writeLine(ConnectionInterface $connection, string $line): void
    {
        $connection->writeLine($line);
        $this->logger->debug('> ' . $line);
    }
}
