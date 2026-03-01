<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\ChanServService;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\ServiceCommandListenerInterface;
use App\Domain\ChanServ\Exception\ChannelAlreadyRegisteredException;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Exception\InsufficientAccessException;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\ChanServ\Bot\ChanServBot;
use App\Infrastructure\NickServ\UserMessageTypeResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Registers ChanServ with the Service Command Gateway. Receives (senderUid, text)
 * and delegates to ChanServService with SenderView from NetworkUserLookupPort.
 */
final readonly class ChanServCommandListener implements ServiceCommandListenerInterface
{
    public function __construct(
        private ChanServBot $chanServBot,
        private ChanServService $chanServService,
        private NetworkUserLookupPort $userLookup,
        private ChanServNotifierInterface $chanServNotifier,
        private UserMessageTypeResolver $messageTypeResolver,
        private TranslatorInterface $translator,
        private RegisteredNickRepositoryInterface $nickRepository,
        private string $defaultLanguage,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function getServiceName(): string
    {
        return $this->chanServBot->getNick();
    }

    public function getServiceUid(): ?string
    {
        return $this->chanServBot->getUid();
    }

    public function onCommand(string $senderUid, string $text): void
    {
        if ('' === $text) {
            return;
        }

        $sender = $this->userLookup->findByUid($senderUid);

        if (null === $sender) {
            $this->logger->warning('ChanServ: could not resolve sender UID: ' . $senderUid);

            return;
        }

        try {
            $this->chanServService->dispatch($text, $sender);
        } catch (ChannelAlreadyRegisteredException $e) {
            $messageType = $this->messageTypeResolver->resolve($sender);
            $this->chanServNotifier->sendMessage($sender->uid, $e->getMessage(), $messageType);
        } catch (ChannelNotRegisteredException $e) {
            $messageType = $this->messageTypeResolver->resolve($sender);
            $language = $this->nickRepository->findByNick($sender->nick)?->getLanguage() ?? $this->defaultLanguage;
            $message = $this->translator->trans('error.channel_not_registered', ['%channel%' => $e->getChannelName()], 'chanserv', $language);
            $this->chanServNotifier->sendMessage($sender->uid, $message, $messageType);
        } catch (InsufficientAccessException $e) {
            $messageType = $this->messageTypeResolver->resolve($sender);
            $language = $this->nickRepository->findByNick($sender->nick)?->getLanguage() ?? $this->defaultLanguage;
            $message = $this->translator->trans('error.insufficient_access', [
                '%operation%' => $e->getOperation(),
                '%channel%' => $e->getChannelName(),
            ], 'chanserv', $language);
            $this->chanServNotifier->sendMessage($sender->uid, $message, $messageType);
        } catch (Throwable $e) {
            $this->logger->error('ChanServ dispatch error: ' . $e->getMessage(), [
                'exception' => $e,
                'sender' => $sender->uid,
            ]);
        }
    }
}
