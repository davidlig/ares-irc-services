<?php

declare(strict_types=1);

namespace App\Infrastructure\MemoServ\Bot;

use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Application\Port\SendNoticePort;
use App\Application\Port\ServiceUidGeneratorInterface;
use App\Application\Port\ServiceUidProviderInterface;
use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * MemoServ pseudo-client: introduces on burst, implements MemoServNotifierInterface.
 */
final class MemoServBot implements MemoServNotifierInterface, ServiceNicknameProviderInterface, ServiceUidProviderInterface, EventSubscriberInterface
{
    private string $uid = '';

    public function __construct(
        private readonly ActiveConnectionHolder $connectionHolder,
        private readonly SendNoticePort $sendNoticePort,
        private readonly ServiceUidGeneratorInterface $uidGenerator,
        private readonly string $servicesVhost,
        private readonly string $memoservNick = 'MemoServ',
        private readonly string $memoservIdent = 'MemoServ',
        private readonly string $memoservRealname = 'Memo Service',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 94],
        ];
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        $this->uid = $this->uidGenerator->generateUid('memoserv');
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
            $this->memoservNick,
            $this->memoservIdent,
            $this->servicesVhost,
            $this->uid,
            $this->memoservRealname,
            $this->getServiceKey(),
        );

        $this->logger->info('MemoServ introduced to network.', [
            'uid' => $this->uid,
            'nick' => $this->memoservNick,
        ]);
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
        return $this->memoservNick;
    }

    public function getUid(): string
    {
        return $this->uid;
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
