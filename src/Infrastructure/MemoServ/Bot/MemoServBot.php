<?php

declare(strict_types=1);

namespace App\Infrastructure\MemoServ\Bot;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceUidProviderInterface;
use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Message\MessageDirection;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * MemoServ pseudo-client: introduces on burst, implements MemoServNotifierInterface.
 */
final readonly class MemoServBot implements MemoServNotifierInterface, ServiceNicknameProviderInterface, ServiceUidProviderInterface, EventSubscriberInterface
{
    public function __construct(
        private readonly ActiveConnectionHolder $connectionHolder,
        private readonly string $servicesHostname,
        private readonly string $memoservUid,
        private readonly string $memoservNick = 'MemoServ',
        private readonly string $memoservIdent = 'MemoServ',
        private readonly string $memoservRealname = 'Memo Service',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 94],
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
            $this->memoservNick,
            $this->memoservIdent,
            $this->servicesHostname,
            $this->memoservUid,
            $this->memoservRealname,
        );

        $connection->writeLine($line);

        $this->logger->info('MemoServ introduced to network.', [
            'uid' => $this->memoservUid,
            'nick' => $this->memoservNick,
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
                prefix: $this->memoservUid,
                params: [$targetUidOrNick],
                trailing: $line,
                direction: MessageDirection::Outgoing,
            );
            $rawLine = $module->getHandler()->formatMessage($ircMessage);
            $this->connectionHolder->writeLine($rawLine);
        }
    }

    public function getNick(): string
    {
        return $this->memoservNick;
    }

    public function getUid(): string
    {
        return $this->memoservUid;
    }

    public function getServiceKey(): string
    {
        return 'memoserv';
    }

    public function getNickname(): string
    {
        return $this->memoservNick;
    }
}
