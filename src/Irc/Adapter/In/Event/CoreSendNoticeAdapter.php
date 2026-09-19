<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Event;

use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\MessageDirection;
use App\Irc\Application\Port\In\SendNoticePort;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Core implements SendNoticePort: sends NOTICE/PRIVMSG over the active IRC connection.
 * Services use this port to reply to users without depending on Connection or protocol details.
 */
final readonly class CoreSendNoticeAdapter implements SendNoticePort
{
    public function __construct(
        private ActiveConnectionHolder $connectionHolder,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function sendNotice(string $senderUid, string $targetUid, string $message): void
    {
        $this->sendMessage($senderUid, $targetUid, $message, 'NOTICE');
    }

    public function sendMessage(string $senderUid, string $targetUid, string $message, string $messageType): void
    {
        if (!$this->connectionHolder->isConnected()) {
            $this->logger->warning('CoreSendNoticeAdapter: cannot send message — no active connection.', [
                'sender' => $senderUid,
                'target' => $targetUid,
            ]);

            return;
        }

        $handler = $this->connectionHolder->getProtocolHandler();
        if (null === $handler) {
            $this->logger->warning('CoreSendNoticeAdapter: cannot send message — no active protocol handler.');

            return;
        }

        $command = 'PRIVMSG' === $messageType ? 'PRIVMSG' : 'NOTICE';
        foreach (explode("\n", $message) as $line) {
            if ('' === $line) {
                continue;
            }
            $ircMessage = new IRCMessage(
                command: $command,
                prefix: $senderUid,
                params: [$targetUid],
                trailing: $line,
                direction: MessageDirection::Outgoing,
            );
            $rawLine = $handler->formatMessage($ircMessage);
            $this->connectionHolder->writeLine($rawLine);
        }
    }

    public function sendNoticeToChannel(string $senderUid, string $channelName, string $message): void
    {
        if (!$this->connectionHolder->isConnected()) {
            $this->logger->warning('CoreSendNoticeAdapter: cannot send channel notice — no active connection.', [
                'sender' => $senderUid,
                'channel' => $channelName,
            ]);

            return;
        }

        $handler = $this->connectionHolder->getProtocolHandler();
        if (null === $handler) {
            $this->logger->warning('CoreSendNoticeAdapter: cannot send channel notice — no active protocol handler.');

            return;
        }

        foreach (explode("\n", $message) as $line) {
            if ('' === $line) {
                continue;
            }
            $ircMessage = new IRCMessage(
                command: 'NOTICE',
                prefix: $senderUid,
                params: [$channelName],
                trailing: $line,
                direction: MessageDirection::Outgoing,
            );
            $rawLine = $handler->formatMessage($ircMessage);
            $this->connectionHolder->writeLine($rawLine);
        }
    }
}
