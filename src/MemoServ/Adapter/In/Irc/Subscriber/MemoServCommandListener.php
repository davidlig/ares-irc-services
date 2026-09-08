<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc\Subscriber;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\MemoServ\Adapter\In\Irc\Bot\MemoServBot;
use App\MemoServ\Adapter\In\Irc\MemoServService;
use App\Shared\Application\Port\ServiceCommandListenerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Registers MemoServ with the Service Command Gateway. Receives (senderUid, text)
 * and delegates to MemoServService with SenderView from NetworkUserLookupPort.
 */
final readonly class MemoServCommandListener implements ServiceCommandListenerInterface
{
    public function __construct(
        private MemoServBot $memoServBot,
        private MemoServService $memoServService,
        private NetworkUserLookupPort $userLookup,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function getServiceName(): string
    {
        return $this->memoServBot->getNick();
    }

    public function getServiceUid(): string
    {
        return $this->memoServBot->getUid();
    }

    public function onCommand(string $senderUid, string $text): void
    {
        if ('' === $text) {
            return;
        }

        $sender = $this->userLookup->findByUid($senderUid);

        if (null === $sender) {
            $this->logger->warning('MemoServ: could not resolve sender UID: ' . $senderUid);

            return;
        }

        try {
            $this->memoServService->dispatch($text, $sender);
        } catch (Throwable $e) {
            $this->logger->error('MemoServ dispatch error: ' . $e->getMessage(), [
                'exception' => $e,
                'sender' => $sender->uid,
            ]);
        }
    }
}
