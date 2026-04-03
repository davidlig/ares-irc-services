<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Bot;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceUidProviderInterface;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SendNoticePort;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class OperServBot implements OperServNotifierInterface, ServiceNicknameProviderInterface, ServiceUidProviderInterface, EventSubscriberInterface
{
    public function __construct(
        private ActiveConnectionHolder $connectionHolder,
        private NetworkUserLookupPort $userLookup,
        private SendNoticePort $sendNoticePort,
        private string $servicesVhost,
        private string $operservUid,
        private string $operservNick = 'OperServ',
        private string $operservIdent = 'OperServ',
        private string $operservRealname = 'Network Operations Services',
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 90],
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
            $this->operservNick,
            $this->operservIdent,
            $this->servicesVhost,
            $this->operservUid,
            $this->operservRealname,
        );

        $connection->writeLine($line);

        $this->logger->info('OperServ introduced to network.', [
            'uid' => $this->operservUid,
            'nick' => $this->operservNick,
        ]);
    }

    public function sendNotice(string $targetUidOrNick, string $message): void
    {
        $this->sendNoticePort->sendNotice($this->getUid(), $targetUidOrNick, $message);
    }

    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void
    {
        $this->sendNoticePort->sendMessage($this->getUid(), $targetUidOrNick, $message, $messageType);
    }

    public function getNick(): string
    {
        return $this->operservNick;
    }

    public function getUid(): string
    {
        return $this->operservUid;
    }

    public function getServiceKey(): string
    {
        return 'operserv';
    }

    public function getNickname(): string
    {
        return $this->operservNick;
    }
}
