<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Bot;

use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\Port\ChannelServiceActionsPort;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Message\MessageDirection;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function in_array;

/**
 * ChanServ pseudo-client: introduces on burst, implements ChanServNotifierInterface
 * and ChannelServiceActionsPort. Delegates channel actions to the active protocol module.
 */
readonly class ChanServBot implements ChanServNotifierInterface, ChannelServiceActionsPort, EventSubscriberInterface
{
    /** Preferred order of prefix modes (highest first). */
    private const array PREFIX_ORDER = ['q', 'a', 'o', 'h', 'v'];

    public function __construct(
        private readonly ActiveConnectionHolder $connectionHolder,
        private readonly string $servicesHostname,
        private readonly string $chanservUid,
        private readonly string $chanservNick = 'ChanServ',
        private readonly string $chanservIdent = 'ChanServ',
        private readonly string $chanservRealname = 'Channel Registration Services',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 95],
        ];
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        $this->introduce($event->connection, $event->serverSid);
    }

    private function introduce(ConnectionInterface $connection, string $serverSid): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            return;
        }

        $line = $module->getIntroductionFormatter()->formatIntroduction(
            $serverSid,
            $this->chanservNick,
            $this->chanservIdent,
            $this->servicesHostname,
            $this->chanservUid,
            $this->chanservRealname,
        );

        $connection->writeLine($line);

        $this->logger->info('ChanServ introduced to network.', [
            'uid' => $this->chanservUid,
            'nick' => $this->chanservNick,
        ]);
    }

    public function sendNotice(string $targetUidOrNick, string $message): void
    {
        $this->sendMessage($targetUidOrNick, $message, 'NOTICE');
    }

    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            return;
        }

        $command = 'PRIVMSG' === $messageType ? 'PRIVMSG' : 'NOTICE';
        foreach (explode("\n", $message) as $line) {
            if ('' === $line) {
                continue;
            }
            $ircMessage = new IRCMessage(
                command: $command,
                prefix: $this->chanservUid,
                params: [$targetUidOrNick],
                trailing: $line,
                direction: MessageDirection::Outgoing,
            );
            $rawLine = $module->getHandler()->formatMessage($ircMessage);
            $this->writeToConnection($rawLine);
        }
    }

    public function sendNoticeToChannel(string $channelName, string $message): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            return;
        }

        $ircMessage = new IRCMessage(
            command: 'NOTICE',
            prefix: $this->chanservUid,
            params: [$channelName],
            trailing: $message,
            direction: MessageDirection::Outgoing,
        );
        $rawLine = $module->getHandler()->formatMessage($ircMessage);
        $this->writeToConnection($rawLine);
    }

    public function setChannelModes(string $channelName, string $modeStr, array $params = []): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        $sid = $this->connectionHolder->getServerSid() ?? '';
        if (null !== $module && '' !== $sid) {
            $module->getServiceActions()->setChannelModes($sid, $channelName, $modeStr, $params, $this->chanservUid);
        }
    }

    public function setChannelMemberMode(string $channelName, string $targetUid, string $modeLetter, bool $add): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        $sid = $this->connectionHolder->getServerSid() ?? '';
        if (null !== $module && '' !== $sid) {
            $module->getServiceActions()->setChannelMemberMode($sid, $channelName, $targetUid, $modeLetter, $add, $this->chanservUid);
        }
    }

    public function inviteToChannel(string $channelName, string $targetUid): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        $sid = $this->connectionHolder->getServerSid() ?? '';
        if (null !== $module && '' !== $sid) {
            $module->getServiceActions()->inviteUserToChannel($sid, $channelName, $targetUid, $this->chanservUid);
        }
    }

    public function joinChannelAsService(string $channelName, ?int $channelTimestamp = null): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        $sid = $this->connectionHolder->getServerSid() ?? '';
        if (null === $module || '' === $sid) {
            return;
        }

        $supported = $module->getChannelModeSupport()->getSupportedPrefixModes();
        $maxPrefix = 'o';
        foreach (self::PREFIX_ORDER as $letter) {
            if (in_array($letter, $supported, true)) {
                $maxPrefix = $letter;
                break;
            }
        }

        $module->getServiceActions()->joinChannelAsService($sid, $channelName, $this->chanservUid, $maxPrefix, $channelTimestamp);
    }

    public function setChannelTopic(string $channelName, ?string $topic): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        $sid = $this->connectionHolder->getServerSid() ?? '';
        if (null !== $module && '' !== $sid) {
            $module->getServiceActions()->setChannelTopic($sid, $channelName, $topic, $this->chanservUid);
        }
    }

    private function writeToConnection(string $line): bool
    {
        if (!$this->connectionHolder->isConnected()) {
            return false;
        }
        $this->connectionHolder->writeLine($line);

        return true;
    }

    public function getNick(): string
    {
        return $this->chanservNick;
    }

    public function getUid(): string
    {
        return $this->chanservUid;
    }
}
