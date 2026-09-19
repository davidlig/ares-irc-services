<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealStandalone;

use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Domain\Server\ServerLink;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function implode;
use function sprintf;
use function str_starts_with;
use function substr;
use function time;

/** Implements the standalone UnrealIRCd 4.x / 5.x / 6.x server protocol. */
final class UnrealStandaloneProtocolHandler implements ProtocolHandlerInterface
{
    private const string PROTOCOL_NAME = 'unreal';

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

    private ?string $remoteSid = null;

    private bool $remoteBurstComplete = false;

    public function __construct(
        private readonly string $sid = '001',
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {}

    public function getProtocolName(): string
    {
        return self::PROTOCOL_NAME;
    }

    public function getSupportedCapabilities(): array
    {
        return self::CAPABILITIES;
    }

    public function parseRawLine(string $rawLine): IRCMessage
    {
        return IRCMessage::fromRawLine($rawLine);
    }

    public function formatMessage(IRCMessage $message): string
    {
        return $message->toRawLine();
    }

    public function performHandshake(ConnectionInterface $connection, ServerLink $link): void
    {
        $this->remoteSid = null;
        $this->remoteBurstComplete = false;

        $this->logger->debug('Starting standalone UnrealIRCd handshake.', [
            'server' => (string) $link->serverName,
            'sid' => $this->sid,
        ]);

        $connection->writeLine(sprintf('PASS :%s', $link->password));

        $eauth = sprintf('PROTOCTL EAUTH=%s SID=%s', $link->serverName, $this->sid);
        $connection->writeLine($eauth);
        $this->logger->debug('> ' . $eauth);

        $capabilities = sprintf('PROTOCTL %s', implode(' ', self::CAPABILITIES));
        $connection->writeLine($capabilities);
        $this->logger->debug('> ' . $capabilities);

        $server = sprintf('SERVER %s 1 :%s', $link->serverName, $link->description);
        $connection->writeLine($server);
        $this->logger->debug('> ' . $server);

        $this->logger->info('Standalone UnrealIRCd handshake sent.', [
            'server' => (string) $link->serverName,
            'caps' => self::CAPABILITIES,
        ]);
    }

    public function handleIncoming(IRCMessage $message, ConnectionInterface $connection): void
    {
        match ($message->command) {
            'ERROR' => $this->handleError($message),
            'PING' => $this->handlePing($message, $connection),
            'EOS' => $this->handleEos($message, $connection),
            'NETINFO' => $this->handleNetinfo($message, $connection),
            'PROTOCTL' => $this->captureRemoteSid($message),
            default => null,
        };
    }

    private function handleError(IRCMessage $message): void
    {
        $reason = $message->trailing ?? ($message->params[0] ?? 'unknown');
        $this->logger->critical('Remote server sent ERROR — closing link.', [
            'reason' => $reason,
        ]);
    }

    private function handlePing(IRCMessage $message, ConnectionInterface $connection): void
    {
        $target = $message->trailing ?? ($message->params[0] ?? '');
        $pong = 'PONG :' . $target;
        $connection->writeLine($pong);
        $this->logger->debug('> ' . $pong);
    }

    private function handleEos(IRCMessage $message, ConnectionInterface $connection): void
    {
        if ($this->remoteBurstComplete || null === $this->remoteSid || $message->prefix !== $this->remoteSid) {
            return;
        }

        $this->remoteBurstComplete = true;
        $this->eventDispatcher?->dispatch(new NetworkBurstCompleteEvent($connection, $this->sid));

        $eos = sprintf(':%s EOS', $this->sid);
        $connection->writeLine($eos);
        $this->logger->info('Sent EOS — initial burst and sync complete.', ['sid' => $this->sid]);
        $this->eventDispatcher?->dispatch(new NetworkSyncCompleteEvent($connection, $this->sid));
    }

    private function captureRemoteSid(IRCMessage $message): void
    {
        if (null !== $message->prefix) {
            return;
        }

        foreach ($message->params as $param) {
            if (!str_starts_with($param, 'SID=')) {
                continue;
            }

            $sid = substr($param, 4);
            if ('' !== $sid) {
                $this->remoteSid = $sid;
            }

            return;
        }
    }

    private function handleNetinfo(IRCMessage $message, ConnectionInterface $connection): void
    {
        $networkName = $message->trailing ?? 'IRC Network';
        $netinfo = sprintf('NETINFO 0 %d 6100 * 0 0 0 :%s', time(), $networkName);

        $connection->writeLine($netinfo);
        $this->logger->debug('> ' . $netinfo);
    }
}
