<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\In\Irc\Subscriber;

use App\CRegServ\Adapter\In\Irc\Bot\CRegServBot;
use App\CRegServ\Adapter\In\Irc\CRegServService;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\ServiceCommandListenerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Registers CReG with the Service Command Gateway. Receives (senderUid, text)
 * and delegates to CRegServService with SenderView from NetworkUserLookupPort.
 */
final readonly class CRegServCommandListener implements ServiceCommandListenerInterface
{
    public function __construct(
        private CRegServBot $cRegServBot,
        private CRegServService $cRegServService,
        private NetworkUserLookupPort $userLookup,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function getServiceName(): string
    {
        return $this->cRegServBot->getNick();
    }

    public function getServiceUid(): string
    {
        return $this->cRegServBot->getUid();
    }

    public function onCommand(string $senderUid, string $text): void
    {
        if ('' === $text) {
            return;
        }

        $sender = $this->userLookup->findByUid($senderUid);

        if (null === $sender) {
            $this->logger->warning('CRegServ: could not resolve sender UID: ' . $senderUid);

            return;
        }

        try {
            $this->cRegServService->dispatch($text, $sender);
        } catch (Throwable $e) {
            $this->logger->error('CRegServ dispatch error: ' . $e->getMessage(), [
                'exception' => $e,
                'sender' => $sender->uid,
            ]);
        }
    }
}
