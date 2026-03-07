<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\ServiceBridge;

use App\Application\Port\SendNoticePort;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Message\MessageDirection;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Core implements SendNoticePort: sends NOTICE/PRIVMSG over the active IRC connection
 * using the configured service sender UID (e.g. NickServ). Services use this port
 * to reply to users without depending on Connection or protocol details.
 */
final readonly class CoreSendNoticeAdapter implements SendNoticePort
{
    public function __construct(
        private ActiveConnectionHolder $connectionHolder,
        private string $senderUid,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function sendNotice(string $targetUidOrNick, string $message): void
    {
        $this->sendMessage($targetUidOrNick, $message, 'NOTICE');
    }

    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void
    {
        if (!$this->connectionHolder->isConnected()) {
            $this->logger->warning('CoreSendNoticeAdapter: cannot send message — no active connection.', [
                'target' => $targetUidOrNick,
            ]);

            return;
        }

        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            $this->logger->warning('CoreSendNoticeAdapter: cannot send message — no active protocol module.');

            return;
        }

        $command = 'PRIVMSG' === $messageType ? 'PRIVMSG' : 'NOTICE';
        foreach (explode("\n", $message) as $line) {
            if ('' === $line) {
                continue;
            }
            $ircMessage = new IRCMessage(
                command: $command,
                prefix: $this->senderUid,
                params: [$targetUidOrNick],
                trailing: $line,
                direction: MessageDirection::Outgoing,
            );
            $rawLine = $module->getHandler()->formatMessage($ircMessage);
            $this->connectionHolder->writeLine($rawLine);
        }
    }
}
