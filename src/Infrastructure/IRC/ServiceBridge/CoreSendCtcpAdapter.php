<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\ServiceBridge;

use App\Application\Port\SendCtcpPort;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Message\MessageDirection;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class CoreSendCtcpAdapter implements SendCtcpPort
{
    public function __construct(
        private ActiveConnectionHolder $connectionHolder,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

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

        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            $this->logger->warning('CoreSendCtcpAdapter: cannot send CTCP reply — no active protocol module.');

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
        $rawLine = $module->getHandler()->formatMessage($ircMessage);
        $this->connectionHolder->writeLine($rawLine);
    }
}
