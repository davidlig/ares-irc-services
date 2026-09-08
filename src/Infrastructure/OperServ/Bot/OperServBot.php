<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Bot;

use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\Port\SendNoticePort;
use App\Application\Port\ServiceUidProviderInterface;
use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\ServiceUidGeneratorInterface;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface as NewOperServNotifierInterface;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class OperServBot implements OperServNotifierInterface, NewOperServNotifierInterface, ServiceNicknameProviderInterface, ServiceUidProviderInterface, EventSubscriberInterface
{
    private string $uid = '';

    public function __construct(
        private readonly ActiveConnectionHolder $connectionHolder,
        private readonly NetworkUserLookupPort $userLookup,
        private readonly SendNoticePort $sendNoticePort,
        private readonly ServiceUidGeneratorInterface $uidGenerator,
        private readonly string $servicesVhost,
        private readonly string $operservNick = 'OperServ',
        private readonly string $operservIdent = 'OperServ',
        private readonly string $operservRealname = 'Network Operations Services',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 90],
        ];
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        $this->uid = $this->uidGenerator->generateUid('operserv');
        $this->introduce($event->connection, $event->serverSid);
    }

    private function introduce(ConnectionInterface $connection, string $serverSid): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            return;
        }

        $module->getServiceActions()->introduceService(
            $serverSid,
            $this->operservNick,
            $this->operservIdent,
            $this->servicesVhost,
            $this->uid,
            $this->operservRealname,
            $this->getServiceKey(),
        );

        $this->logger->info('OperServ introduced to network.', [
            'uid' => $this->uid,
            'nick' => $this->operservNick,
        ]);
    }

    public function getUserLookup(): NetworkUserLookupPort
    {
        return $this->userLookup;
    }

    public function sendNotice(string $targetUidOrNick, string $message): void
    {
        $this->sendNoticePort->sendNotice($this->uid, $targetUidOrNick, $message);
    }

    /**
     * @param 'NOTICE'|'PRIVMSG' $messageType
     */
    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void
    {
        $this->sendNoticePort->sendMessage($this->uid, $targetUidOrNick, $message, $messageType);
    }

    public function getNick(): string
    {
        return $this->operservNick;
    }

    public function getUid(): string
    {
        return $this->uid;
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
