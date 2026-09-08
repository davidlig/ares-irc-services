<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Event;

use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\MessageDirection;
use App\Shared\Application\Port\SendCtcpPort;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class CoreSendCtcpAdapter implements SendCtcpPort
{
    public function __construct(
        private ActiveConnectionHolder $connectionHolder,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function sendCtcpReply(string $senderUid, string $targetUid, string $command, string $response): void
    {
        if (!$this->connectionHolder->isConnected()) {
            $this->logger->warning('CoreSendCtcpAdapter: cannot send CTCP reply — no active connection.', [
                'sender' => $senderUid,
                'target' => $targetUid,
                'command' => $command,
            ]);

            return;
        }

        $handler = $this->connectionHolder->getProtocolHandler();
        if (null === $handler) {
            $this->logger->warning('CoreSendCtcpAdapter: cannot send CTCP reply — no active protocol handler.');

            return;
        }

        $ctcpMessage = "\x01{$command} {$response}\x01";
        $ircMessage = new IRCMessage(
            command: 'NOTICE',
            prefix: $senderUid,
            params: [$targetUid],
            trailing: $ctcpMessage,
            direction: MessageDirection::Outgoing,
        );
        $rawLine = $handler->formatMessage($ircMessage);
        $this->connectionHolder->writeLine($rawLine);
    }
}
