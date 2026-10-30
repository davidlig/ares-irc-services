<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Subscriber;

use App\ChanServ\Adapter\In\Irc\Bot\ChanServBot;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\ChanServService;
use App\ChanServ\Adapter\In\Irc\ChanServUserPresentationPreferences;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Domain\Exception\ChannelAlreadyRegisteredException;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\ChanServ\Domain\Exception\InsufficientAccessException;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\ServiceCommandListenerInterface;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use App\Shared\Adapter\In\Irc\Presentation\Role;
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
        private ChanServUserPresentationPreferences $messageTypeResolver,
        private TranslatorInterface $translator,
        private ChanUserAccountPort $accountPort,
        private string $defaultLanguage,
        private LoggerInterface $logger = new NullLogger(),
        private ?IrcRenderer $renderer = null,
    ) {}

    public function getServiceName(): string
    {
        return $this->chanServBot->getNick();
    }

    public function getServiceUid(): string
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
            $messageType = $this->messageTypeResolver->prefersPrivateMessages($sender->nick) ? 'PRIVMSG' : 'NOTICE';
            $language = $this->accountPort->findAccountByNick($sender->nick)->language ?? $this->defaultLanguage;
            $message = $this->renderError('register.already_registered', $language, [
                '%channel%' => new Fragment($e->getChannelName(), Component::Emphasis),
            ], 'chanserv');
            $this->chanServNotifier->sendMessage($sender->uid, $message, $messageType);
        } catch (ChannelNotRegisteredException $e) {
            $messageType = $this->messageTypeResolver->prefersPrivateMessages($sender->nick) ? 'PRIVMSG' : 'NOTICE';
            $language = $this->accountPort->findAccountByNick($sender->nick)->language ?? $this->defaultLanguage;
            $message = $this->renderError('presentation.error.channel_not_registered', $language, [
                '%channel%' => new Fragment($e->getChannelName(), Component::Emphasis),
            ]);
            $this->chanServNotifier->sendMessage($sender->uid, $message, $messageType);
        } catch (InsufficientAccessException $e) {
            $messageType = $this->messageTypeResolver->prefersPrivateMessages($sender->nick) ? 'PRIVMSG' : 'NOTICE';
            $language = $this->accountPort->findAccountByNick($sender->nick)->language ?? $this->defaultLanguage;
            $message = $this->renderError('presentation.error.insufficient_access', $language, [
                '%operation%' => new Fragment($e->getOperation(), Component::Emphasis),
                '%channel%' => new Fragment($e->getChannelName(), Component::Emphasis),
            ]);
            $this->chanServNotifier->sendMessage($sender->uid, $message, $messageType);
        } catch (Throwable $e) {
            $this->logger->error('ChanServ dispatch error: ' . $e->getMessage(), [
                'exception' => $e,
                'sender' => $sender->uid,
            ]);
        }
    }

    /** @param array<string, string|Fragment> $params */
    private function renderError(string $key, string $language, array $params, string $domain = 'common'): string
    {
        $renderer = $this->renderer ?? new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));

        return $renderer->reply(Role::Error, $this->translator->trans($key, [], $domain, $language), $params);
    }
}
