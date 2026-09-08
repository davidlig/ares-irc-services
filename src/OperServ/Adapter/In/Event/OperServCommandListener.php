<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Event;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\OperServ\Adapter\In\Irc\Bot\OperServBot;
use App\OperServ\Adapter\In\Irc\OperServCommandLogSanitizer;
use App\OperServ\Adapter\In\Irc\OperServService;
use App\OperServ\Application\Port\Out\ServiceUserPreferences;
use App\Shared\Application\Port\SendNoticePort;
use App\Shared\Application\Port\ServiceCommandListenerInterface;
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
        private ServiceUserPreferences $messageTypeResolver,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function getSendNotice(): SendNoticePort
    {
        return $this->sendNotice;
    }

    public function getMessageTypeResolver(): ServiceUserPreferences
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

        $command = OperServCommandLogSanitizer::commandName($text);
        $this->logger->debug('OperServ: command from {nick} [{uid}]: {command}', [
            'nick' => $sender->nick,
            'uid' => $sender->uid,
            'command' => $command,
        ]);

        try {
            $this->operServService->dispatch($text, $sender);
        } catch (Throwable $e) {
            $this->logger->error('OperServ dispatch error', [
                'exception_class' => $e::class,
                'sender' => $sender->uid,
                'command' => $command,
            ]);
        }
    }
}
