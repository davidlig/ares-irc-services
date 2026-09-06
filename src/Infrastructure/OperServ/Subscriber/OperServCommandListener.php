<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Subscriber;

use App\Application\OperServ\OperServService;
use App\Application\Port\SendNoticePort;
use App\Application\Port\ServiceCommandListenerInterface;
use App\Infrastructure\OperServ\Bot\OperServBot;
use App\Irc\Adapter\Security\SensitiveDataRedactor;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Adapter\Out\User\UserMessageTypeResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

final readonly class OperServCommandListener implements ServiceCommandListenerInterface
{
    public function __construct(
        private OperServBot $operServBot,
        private OperServService $operServService,
        private NetworkUserLookupPort $userLookup,
        private SendNoticePort $sendNotice,
        private UserMessageTypeResolver $messageTypeResolver,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function getSendNotice(): SendNoticePort
    {
        return $this->sendNotice;
    }

    public function getMessageTypeResolver(): UserMessageTypeResolver
    {
        return $this->messageTypeResolver;
    }

    public function getServiceName(): string
    {
        return $this->operServBot->getNick();
    }

    public function getServiceUid(): string
    {
        return $this->operServBot->getUid();
    }

    public function onCommand(string $senderUid, string $text): void
    {
        if ('' === $text) {
            return;
        }

        $sender = $this->userLookup->findByUid($senderUid);

        if (null === $sender) {
            $this->logger->warning('OperServ: could not resolve sender UID: ' . $senderUid);

            return;
        }

        $this->logger->debug('OperServ: command from {nick} [{uid}]: {text}', [
            'nick' => $sender->nick,
            'uid' => $sender->uid,
            'text' => SensitiveDataRedactor::redactNickServCommand($text),
        ]);

        try {
            $this->operServService->dispatch($text, $sender);
        } catch (Throwable $e) {
            $this->logger->error('OperServ dispatch error: ' . $e->getMessage(), [
                'exception' => $e,
                'sender' => $sender->uid,
                'text' => SensitiveDataRedactor::redactNickServCommand($text),
            ]);
        }
    }
}
