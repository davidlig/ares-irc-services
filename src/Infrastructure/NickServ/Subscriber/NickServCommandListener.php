<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Subscriber;

use App\Application\NickServ\NickServService;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SendNoticePort;
use App\Application\Port\ServiceCommandListenerInterface;
use App\Domain\NickServ\Exception\InvalidCredentialsException;
use App\Domain\NickServ\Exception\NickAlreadyRegisteredException;
use App\Infrastructure\IRC\Security\SensitiveDataRedactor;
use App\Infrastructure\NickServ\Bot\NickServBot;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Registers NickServ with the Service Command Gateway. Receives (senderUid, text)
 * and delegates to NickServService with SenderView from NetworkUserLookupPort.
 *
 * Replaces direct subscription to MessageReceivedEvent; no Domain\IRC repos in this class.
 */
final readonly class NickServCommandListener implements ServiceCommandListenerInterface
{
    public function __construct(
        private NickServBot $nickServBot,
        private NickServService $nickServService,
        private NetworkUserLookupPort $userLookup,
        private SendNoticePort $sendNotice,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function getServiceName(): string
    {
        return $this->nickServBot->getNick();
    }

    public function getServiceUid(): ?string
    {
        return $this->nickServBot->getUid();
    }

    public function onCommand(string $senderUid, string $text): void
    {
        if ('' === $text) {
            return;
        }

        $sender = $this->userLookup->findByUid($senderUid);

        if (null === $sender) {
            $this->logger->warning('NickServ: could not resolve sender UID: ' . $senderUid);

            return;
        }

        $this->logger->debug('NickServ: command from {nick} [{uid}]: {text}', [
            'nick' => $sender->nick,
            'uid' => $sender->uid,
            'text' => SensitiveDataRedactor::redactNickServCommand($text),
        ]);

        try {
            $this->nickServService->dispatch($text, $sender);
        } catch (NickAlreadyRegisteredException $e) {
            $this->sendNotice->sendNotice($sender->uid, $e->getMessage());
        } catch (InvalidCredentialsException $e) {
            $this->sendNotice->sendNotice($sender->uid, $e->getMessage());
        } catch (Throwable $e) {
            $this->logger->error('NickServ dispatch error: ' . $e->getMessage(), [
                'exception' => $e,
                'sender' => $sender->uid,
                'text' => SensitiveDataRedactor::redactNickServCommand($text),
            ]);
        }
    }
}
