<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc\Bot;

use App\Irc\Application\Port\In\ServiceUidGeneratorInterface;
use App\Irc\Application\PublishedEvent\ServiceIntroductionRequestedEvent;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\Shared\Application\Port\ActiveConnectionHolderInterface;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\Port\SendNoticePort;
use App\Shared\Application\Port\ServiceUidProviderInterface;
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
        private readonly ActiveConnectionHolderInterface $connectionHolder,
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
            ServiceIntroductionRequestedEvent::class => ['onBurstComplete', 94],
        ];
    }

    public function onBurstComplete(ServiceIntroductionRequestedEvent $event): void
    {
        $this->uid = $this->uidGenerator->generateUid('memoserv');
        $this->introduce($event->serverSid);
    }

    private function introduce(string $serverSid): void
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
