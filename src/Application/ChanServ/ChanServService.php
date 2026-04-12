<?php

declare(strict_types=1);

namespace App\Application\ChanServ;

use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\Command\AuditableCommandInterface;
use App\Application\NickServ\Security\AuthorizationCheckerInterface;
use App\Application\NickServ\Security\AuthorizationContextInterface;
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
use App\Domain\IRC\Event\IrcopCommandExecutedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function array_slice;
use function base64_decode;
use function count;
use function inet_ntop;
use function sprintf;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Routes a raw command string (from a PRIVMSG to ChanServ) to the correct
 * command handler. Builds ChanServContext with ports and mode support.
 */
final readonly class ChanServService implements ChanServDispatchPort
{
    public function __construct(
        private readonly ChanServCommandRegistry $commandRegistry,
        private readonly RegisteredChannelRepositoryInterface $channelRepository,
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly ChanServNotifierInterface $notifier,
        private readonly UserMessageTypeResolverInterface $messageTypeResolver,
        private readonly TranslatorInterface $translator,
        private readonly ChannelLookupPort $channelLookup,
        private readonly ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private readonly NetworkUserLookupPort $userLookup,
        private readonly ServiceNicknameRegistry $serviceNicks,
        private readonly AuthorizationContextInterface $authorizationContext,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly string $defaultLanguage = 'en',
        private readonly string $defaultTimezone = 'UTC',
        private readonly LoggerInterface $logger = new NullLogger(),
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
            serviceNicks: $this->serviceNicks,
        );

        $this->authorizationContext->setCurrentUser($sender);

        try {
            $requiredPermission = $handler->getRequiredPermission();
            if (null !== $requiredPermission && !$this->authorizationChecker->isGranted($requiredPermission, $context)) {
                if ('IDENTIFIED' === $requiredPermission) {
                    $context->reply('error.not_identified');
                } else {
                    $context->reply('error.permission_denied');
                }

                return;
            }

            $isLevelFounder = $this->authorizationChecker->isGranted(ChanServPermission::LEVEL_FOUNDER, $context);

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
                serviceNicks: $this->serviceNicks,
                isLevelFounder: $isLevelFounder,
            );

            if (count($args) < $handler->getMinArgs()) {
                $context->reply('error.syntax', [
                    'syntax' => $context->trans($handler->getSyntaxKey()),
                ]);

                return;
            }

            if (!$handler->allowsForbiddenChannel()) {
                $channelName = $context->getChannelNameArg(0);
                if (null !== $channelName) {
                    $channel = $this->channelRepository->findByChannelName($channelName);
                    if (null !== $channel && $channel->isForbidden()) {
                        $context->reply('forbid.channel_forbidden', ['%channel%' => $channelName]);

                        return;
                    }
                }
            }

            if (!$handler->allowsSuspendedChannel() && !$isLevelFounder) {
                $channelName = $context->getChannelNameArg(0);
                if (null !== $channelName) {
                    $channel = $this->channelRepository->findByChannelName($channelName);
                    if (null !== $channel && $channel->isCurrentlySuspended()) {
                        $context->reply('suspend.channel_suspended', ['%channel%' => $channelName]);

                        return;
                    }
                }
            }

            $this->logger->debug(sprintf(
                'ChanServ: %s executed %s [args: %d]',
                $sender->nick,
                $cmdName,
                count($args),
            ));

            $handler->execute($context);

            if (null !== $requiredPermission) {
                $auditData = $handler instanceof AuditableCommandInterface
                    ? $handler->getAuditData($context)
                    : null;

                if (null !== $auditData) {
                    $this->eventDispatcher->dispatch(new IrcopCommandExecutedEvent(
                        serviceName: $this->notifier->getServiceKey(),
                        operatorNick: $sender->nick,
                        commandName: $cmdName,
                        permission: $requiredPermission,
                        target: $auditData->target,
                        targetHost: $auditData->targetHost,
                        targetIp: $auditData->targetIp,
                        reason: $auditData->reason,
                        extra: $auditData->extra,
                    ));
                }
            }

            if ($isLevelFounder && null !== $account && $handler->usesLevelFounder()) {
                $auditChannelName = $context->getChannelNameArg(0);
                if (null !== $auditChannelName) {
                    $auditChannel = $this->channelRepository->findByChannelName($auditChannelName);
                    if (null !== $auditChannel && !$auditChannel->isFounder($account->getId())) {
                        $auditExtra = ['founder_action' => true];
                        if (count($args) >= 2) {
                            $auditExtra['option'] = strtoupper($args[1]);
                        }
                        if (count($args) >= 3) {
                            $auditExtra['value'] = implode(' ', array_slice($args, 2));
                        }

                        $this->eventDispatcher->dispatch(new IrcopCommandExecutedEvent(
                            serviceName: $this->notifier->getServiceKey(),
                            operatorNick: $sender->nick,
                            commandName: $cmdName,
                            permission: ChanServPermission::LEVEL_FOUNDER,
                            target: $auditChannelName,
                            targetHost: sprintf('%s@%s', $sender->ident, $sender->hostname),
                            targetIp: $this->decodeIp($sender->ipBase64),
                            extra: $auditExtra,
                        ));
                    }
                }
            }
        } catch (ChannelNotRegisteredException|ChannelAlreadyRegisteredException|InsufficientAccessException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('ChanServ dispatch error: ' . $e->getMessage(), [
                'exception' => $e,
                'sender' => $sender->uid,
            ]);
            throw $e;
        } finally {
            $this->authorizationContext->clear();
        }
    }

    private function decodeIp(string $ipBase64): string
    {
        if ('' === $ipBase64 || '*' === $ipBase64) {
            return '*';
        }

        $binary = base64_decode($ipBase64, true);

        if (false === $binary) {
            return $ipBase64;
        }

        $ip = inet_ntop($binary);

        return false !== $ip ? $ip : $ipBase64;
    }
}
