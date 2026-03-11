<?php

declare(strict_types=1);

namespace App\Infrastructure\MemoServ\Subscriber;

use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Application\MemoServ\MemoServService;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\ServiceCommandListenerInterface;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Exception\InsufficientAccessException;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\MemoServ\Bot\MemoServBot;
use App\Infrastructure\NickServ\UserMessageTypeResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;
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
        private MemoServNotifierInterface $memoServNotifier,
        private UserMessageTypeResolver $messageTypeResolver,
        private TranslatorInterface $translator,
        private RegisteredNickRepositoryInterface $nickRepository,
        private string $defaultLanguage,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function getServiceName(): string
    {
        return $this->memoServBot->getNick();
    }

    public function getServiceUid(): ?string
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
        } catch (ChannelNotRegisteredException $e) {
            $messageType = $this->messageTypeResolver->resolve($sender);
            $language = $this->nickRepository->findByNick($sender->nick)?->getLanguage() ?? $this->defaultLanguage;
            $message = $this->translator->trans('error.channel_not_registered', ['%channel%' => $e->getChannelName()], 'memoserv', $language);
            $this->memoServNotifier->sendMessage($sender->uid, $message, $messageType);
        } catch (InsufficientAccessException $e) {
            $messageType = $this->messageTypeResolver->resolve($sender);
            $language = $this->nickRepository->findByNick($sender->nick)?->getLanguage() ?? $this->defaultLanguage;
            $message = $this->translator->trans('error.insufficient_access', [
                '%operation%' => $e->getOperation(),
                '%channel%' => $e->getChannelName(),
            ], 'memoserv', $language);
            $this->memoServNotifier->sendMessage($sender->uid, $message, $messageType);
        } catch (Throwable $e) {
            $this->logger->error('MemoServ dispatch error: ' . $e->getMessage(), [
                'exception' => $e,
                'sender' => $sender->uid,
            ]);
        }
    }
}
