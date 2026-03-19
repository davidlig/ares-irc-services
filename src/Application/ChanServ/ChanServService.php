<?php

declare(strict_types=1);

namespace App\Application\ChanServ;

use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChanServDispatchPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Application\Port\UserMessageTypeResolverInterface;
use App\Domain\ChanServ\Exception\ChannelAlreadyRegisteredException;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Exception\InsufficientAccessException;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function count;
use function sprintf;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Routes a raw command string (from a PRIVMSG to ChanServ) to the correct
 * command handler. Builds ChanServContext with ports and mode support.
 */
final readonly class ChanServService implements ChanServDispatchPort
{
    public function __construct(
        private ChanServCommandRegistry $commandRegistry,
        private RegisteredChannelRepositoryInterface $channelRepository,
        private RegisteredNickRepositoryInterface $nickRepository,
        private ChanServNotifierInterface $notifier,
        private UserMessageTypeResolverInterface $messageTypeResolver,
        private TranslatorInterface $translator,
        private ChannelLookupPort $channelLookup,
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private NetworkUserLookupPort $userLookup,
        private string $defaultLanguage = 'en',
        private string $defaultTimezone = 'UTC',
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param string     $rawText Full text of the PRIVMSG (e.g. "REGISTER #channel desc")
     * @param SenderView $sender  The user who sent the message (from NetworkUserLookupPort)
     */
    public function dispatch(string $rawText, SenderView $sender): void
    {
        $parts = preg_split('/\s+/', trim($rawText), -1, PREG_SPLIT_NO_EMPTY);
        $cmdName = strtoupper(array_shift($parts) ?? '');
        $args = $parts;

        if ('' === $cmdName) {
            return;
        }

        $handler = $this->commandRegistry->find($cmdName);

        if (null === $handler) {
            $messageType = $this->messageTypeResolver->resolve($sender);
            $this->notifier->sendMessage(
                $sender->uid,
                $this->translator->trans('unknown_command', ['%command%' => $cmdName, '%bot%' => $this->notifier->getNick()], 'chanserv', $this->defaultLanguage),
                $messageType
            );

            return;
        }

        $account = $this->nickRepository->findByNick($sender->nick);
        $language = $account?->getLanguage() ?? $this->defaultLanguage;
        $timezone = $account?->getTimezone() ?? $this->defaultTimezone;
        $messageType = $this->messageTypeResolver->resolve($sender);
        $modeSupport = $this->modeSupportProvider->getSupport();

        $context = new ChanServContext(
            sender: $sender,
            senderAccount: $account,
            command: $cmdName,
            args: $args,
            notifier: $this->notifier,
            translator: $this->translator,
            language: $language,
            timezone: $timezone,
            messageType: $messageType,
            registry: $this->commandRegistry,
            channelLookup: $this->channelLookup,
            channelModeSupport: $modeSupport,
            userLookup: $this->userLookup,
        );

        try {
            if ($handler->isOperOnly()) {
                $context->reply('error.oper_only');

                return;
            }

            $requiredPermission = $handler->getRequiredPermission();
            if ('IDENTIFIED' === $requiredPermission && null === $account) {
                $context->reply('error.not_identified');

                return;
            }

            if (count($args) < $handler->getMinArgs()) {
                $context->reply('error.syntax', [
                    'syntax' => $context->trans($handler->getSyntaxKey()),
                ]);

                return;
            }

            $this->logger->debug(sprintf(
                'ChanServ: %s executed %s [args: %d]',
                $sender->nick,
                $cmdName,
                count($args),
            ));

            $handler->execute($context);
        } catch (ChannelNotRegisteredException|ChannelAlreadyRegisteredException|InsufficientAccessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('ChanServ dispatch error: ' . $e->getMessage(), [
                'exception' => $e,
                'sender' => $sender->uid,
            ]);
            throw $e;
        }
    }
}
