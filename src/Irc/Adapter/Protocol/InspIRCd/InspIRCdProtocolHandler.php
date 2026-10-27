<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\InspIRCd;

use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Adapter\Security\SensitiveDataRedactor;
use App\Irc\Domain\Server\ServerLink;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_filter;
use function array_values;
use function implode;
use function sprintf;
use function str_starts_with;
use function strpos;
use function strtoupper;
use function substr;
use function time;

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
final class InspIRCdProtocolHandler implements ProtocolHandlerInterface
{
    private const string PROTOCOL_NAME = 'inspircd';

    private const int PROTOCOL_VERSION = 1206;

    /** @var list<string> */
    private const array REQUIRED_MODULES = ['services', 'account', 'chghost', 'cloak'];

    private bool $outgoingBurstSent = false;

    private ?string $remoteSid = null;

    private bool $remoteBurstComplete = false;

    /** @var list<string> Accumulated raw CAPAB lines from the remote server */
    private array $remoteCapabLines = [];

    private bool $remoteCapabActive = false;

    private ?string $remoteProtocolVersion = null;

    private bool $remoteCapabValidated = false;

    private bool $remoteCapabRejected = false;

    public function __construct(
        private readonly string $sid = '0A0',
        private readonly ?ActiveConnectionHolder $connectionHolder = null,
        private readonly ?InspIRCdChannelModeSupportFactory $modeSupportFactory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {}

    public function getProtocolName(): string
    {
        return self::PROTOCOL_NAME;
    }

    public function getSupportedCapabilities(): array
    {
        return [];
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

        return IRCMessage::fromRawLine($rawLine);
    }

    public function formatMessage(IRCMessage $message): string
    {
        return $message->toRawLine();
    }

    public function performHandshake(ConnectionInterface $connection, ServerLink $link): void
    {
        $this->logger->debug('Starting InspIRCd handshake.', [
            'server' => (string) $link->serverName,
            'sid' => $this->sid,
        ]);

        $this->outgoingBurstSent = false;
        $this->remoteSid = null;
        $this->remoteBurstComplete = false;
        $this->remoteCapabLines = [];
        $this->remoteCapabActive = false;
        $this->remoteProtocolVersion = null;
        $this->remoteCapabValidated = false;
        $this->remoteCapabRejected = false;
        $this->sendCapabilities($connection);
        $this->sendServerLine($connection, $link);

        $this->logger->info('InspIRCd handshake sent.', [
            'server' => (string) $link->serverName,
            'sid' => $this->sid,
        ]);
    }

    public function handleIncoming(IRCMessage $message, ConnectionInterface $connection): void
    {
        match ($message->command) {
            'PING' => $this->handlePing($message, $connection),
            'ERROR' => $this->handleError($message),
            'CAPAB' => $this->handleCapab($message, $connection),
            'SERVER' => $this->handleRemoteServer($message, $connection),
            'ENDBURST' => $this->handleEndburst($message, $connection),
            default => null,
        };
    }

    private function handleError(IRCMessage $message): void
    {
        $reason = $message->trailing ?? ($message->params[0] ?? 'unknown');
        $this->logger->critical('Remote server sent ERROR — closing link.', [
            'reason' => SensitiveDataRedactor::redactIrcLine($reason),
        ]);
    }

    private function handleCapab(IRCMessage $message, ConnectionInterface $connection): void
    {
        if ($this->remoteCapabRejected) {
            return;
        }

        $subCommand = strtoupper($message->params[0] ?? '');

        if ('START' === $subCommand) {
            $this->remoteCapabLines = [];
            $this->remoteCapabActive = true;
            $this->remoteProtocolVersion = $message->params[1] ?? null;
            $this->remoteCapabValidated = false;

            return;
        }

        if ('END' === $subCommand) {
            $this->remoteCapabActive = false;
            if (!$this->validateRemoteCapab()) {
                $this->rejectLink($connection, $this->capabFailureReason());

                return;
            }
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

    private function validateRemoteCapab(): bool
    {
        if ((string) self::PROTOCOL_VERSION !== $this->remoteProtocolVersion) {
            return false;
        }

        $capab = InspIRCdCapab::fromCapabLines($this->remoteCapabLines);
        foreach (self::REQUIRED_MODULES as $module) {
            if (!$capab->hasModule($module)) {
                return false;
            }
        }

        $this->remoteCapabValidated = true;

        return true;
    }

    private function capabFailureReason(): string
    {
        $capab = InspIRCdCapab::fromCapabLines($this->remoteCapabLines);
        $missingModules = array_values(array_filter(
            self::REQUIRED_MODULES,
            static fn (string $module): bool => !$capab->hasModule($module),
        ));

        return sprintf(
            'CAPAB prerequisites missing or invalid (expected protocol %d, received %s; missing modules: %s).',
            self::PROTOCOL_VERSION,
            $this->remoteProtocolVersion ?? 'missing',
            [] === $missingModules ? 'none' : implode(', ', $missingModules),
        );
    }

    private function rejectLink(ConnectionInterface $connection, string $reason): void
    {
        if ($this->remoteCapabRejected) {
            return;
        }

        $this->remoteCapabRejected = true;
        $this->logger->critical('Rejecting InspIRCd link before startup.', ['reason' => $reason]);
        $connection->disconnect();
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
        if (!$this->remoteCapabValidated || $this->remoteCapabRejected) {
            $this->rejectLink($connection, 'Received SERVER before valid CAPAB 1206 prerequisites.');

            return;
        }

        if (null !== $message->prefix) {
            return;
        }

        $remoteSid = $message->params[2] ?? null;
        $remoteName = $message->params[0] ?? 'unknown';
        $remoteDescription = $message->trailing ?? '';

        if (null !== $remoteSid) {
            $this->remoteSid = $remoteSid;
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

        $this->eventDispatcher?->dispatch(new NetworkBurstCompleteEvent($connection, $this->sid));

        $endburst = sprintf(':%s ENDBURST', $this->sid);
        $this->writeLine($connection, $endburst);
        $this->logger->info('Sent ENDBURST — outgoing burst complete.', ['sid' => $this->sid]);
    }

    private function handleEndburst(IRCMessage $message, ConnectionInterface $connection): void
    {
        if ($this->remoteBurstComplete || null === $this->remoteSid || (null !== $message->prefix && $message->prefix !== $this->remoteSid)) {
            return;
        }

        $this->remoteBurstComplete = true;
        $this->logger->info('Received ENDBURST — remote burst complete, network synced.', ['sid' => $this->sid]);
        $this->eventDispatcher?->dispatch(new NetworkSyncCompleteEvent($connection, $this->sid));
    }

    private function writeLine(ConnectionInterface $connection, string $line): void
    {
        $connection->writeLine($line);
        $this->logger->debug('> ' . SensitiveDataRedactor::redactIrcLine($line));
    }
}
