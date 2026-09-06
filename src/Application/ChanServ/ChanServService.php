<?php

declare(strict_types=1);

namespace App\Application\ChanServ;

use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\Command\CommandOutcome;
use App\Application\Event\CommandExecutedEvent;
use App\Application\Event\IrcopCommandExecutedEvent;
use App\Application\NickServ\Security\AuthorizationCheckerInterface;
use App\Application\NickServ\Security\AuthorizationContextInterface;
use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChanServDispatchPort;
use App\Application\Port\EventBusInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Application\Port\TranslationInterface;
use App\Application\Port\UserLanguageResolverInterface;
use App\Application\Port\UserMessageTypeResolverInterface;
use App\Domain\ChanServ\Exception\ChannelAlreadyRegisteredException;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Exception\InsufficientAccessException;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_slice;
use function base64_decode;
use function count;
use function in_array;
use function inet_ntop;
use function is_string;
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
        private UserLanguageResolverInterface $languageResolver,
        private ChanServNotifierInterface $notifier,
        private UserMessageTypeResolverInterface $messageTypeResolver,
        private TranslationInterface $translator,
        private ChannelLookupPort $channelLookup,
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private NetworkUserLookupPort $userLookup,
        private ServiceNicknameRegistry $serviceNicks,
        private AuthorizationContextInterface $authorizationContext,
        private AuthorizationCheckerInterface $authorizationChecker,
        private EventBusInterface $eventDispatcher,
        private string $defaultLanguage = 'en',
        private string $defaultTimezone = 'UTC',
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param string     $rawText Full text of the PRIVMSG (e.g. "REGISTER #channel desc")
     * @param SenderView $sender  The user who sent the message (from NetworkUserLookupPort)
     */
    public function dispatch(string $rawText, SenderView $sender): void
    {
        $parts = preg_split('/\s+/', trim($rawText), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $cmdPart = array_shift($parts);
        $cmdName = strtoupper(is_string($cmdPart) ? $cmdPart : '');
        /** @var list<string> $args */
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

        $this->executeHandler($handler, $sender, $cmdName, $args);
    }

    /**
     * @param list<string> $args
     */
    private function executeHandler(ChanServCommandInterface $handler, SenderView $sender, string $cmdName, array $args): void
    {
        $account = $this->nickRepository->findByNick($sender->nick);
        $language = $this->languageResolver->resolveFromAccount($sender, $account);
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
                $context->reply('IDENTIFIED' === $requiredPermission ? 'error.not_identified' : 'error.permission_denied');

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

            $validationKey = $this->validateDispatchContext($context, $handler, $isLevelFounder);
            if (null !== $validationKey) {
                return;
            }

            $this->logger->debug(sprintf(
                'ChanServ: %s executed %s [args: %d]',
                $sender->nick,
                $cmdName,
                count($args),
            ));

            $result = $handler->execute($context);

            $this->eventDispatcher->dispatch(new CommandExecutedEvent(
                command: $handler,
                serviceName: $this->notifier->getServiceKey(),
                operatorNick: $sender->nick,
                commandName: $cmdName,
                permission: $requiredPermission,
                outcome: $result instanceof CommandOutcome ? $result : null,
            ));

            $this->dispatchFounderAuditEvent($handler, $context, $sender, $cmdName, $args, $isLevelFounder, $account);
        } catch (ChannelAlreadyRegisteredException|ChannelNotRegisteredException|InsufficientAccessException $e) {
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

    private function validateDispatchContext(ChanServContext $context, ChanServCommandInterface $handler, bool $isLevelFounder): ?string
    {
        if (count($context->args) < $handler->getMinArgs()) {
            $context->reply('error.syntax', [
                'syntax' => $context->trans($handler->getSyntaxKey()),
            ]);

            return 'syntax';
        }

        if ($this->isForbiddenChannelViolation($context, $handler)) {
            return 'forbidden';
        }

        return $this->validateSuspendedAndPending($context, $handler, $isLevelFounder);
    }

    private function validateSuspendedAndPending(ChanServContext $context, ChanServCommandInterface $handler, bool $isLevelFounder): ?string
    {
        if ($this->isSuspendedChannelViolation($context, $handler, $isLevelFounder)) {
            return 'suspended';
        }

        return $this->isPendingDeletionViolation($context, $handler);
    }

    private function isForbiddenChannelViolation(ChanServContext $context, ChanServCommandInterface $handler): bool
    {
        if ($handler->allowsForbiddenChannel()) {
            return false;
        }

        $channelName = $context->getChannelNameArg(0);
        if (null !== $channelName) {
            $channel = $this->channelRepository->findByChannelName($channelName);
            if (null !== $channel && $channel->isForbidden()) {
                $context->reply('forbid.channel_forbidden', ['%channel%' => $channelName]);

                return true;
            }
        }

        return false;
    }

    private function isSuspendedChannelViolation(ChanServContext $context, ChanServCommandInterface $handler, bool $isLevelFounder): bool
    {
        if ($handler->allowsSuspendedChannel() || $isLevelFounder) {
            return false;
        }

        $channelName = $context->getChannelNameArg(0);
        if (null !== $channelName) {
            $channel = $this->channelRepository->findByChannelName($channelName);
            if (null !== $channel && $channel->isCurrentlySuspended()) {
                $context->reply('suspend.channel_suspended', ['%channel%' => $channelName]);

                return true;
            }
        }

        return false;
    }

    private function isPendingDeletionViolation(ChanServContext $context, ChanServCommandInterface $handler): ?string
    {
        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName || in_array($handler->getName(), ['INFO', 'RESTORE', 'DROP'], true)) {
            return null;
        }

        $channel = $this->channelRepository->findByChannelName($channelName);
        if (null !== $channel && $channel->isPendingDeletion()) {
            $context->reply('drop.pending_deletion', ['%channel%' => $channelName]);

            return 'pending_deletion';
        }

        return null;
    }

    /**
     * @param list<string> $args
     */
    private function dispatchFounderAuditEvent(ChanServCommandInterface $handler, ChanServContext $context, SenderView $sender, string $cmdName, array $args, bool $isLevelFounder, ?RegisteredNick $account): void
    {
        if ($isLevelFounder && null !== $account && $handler->usesLevelFounder()) {
            $auditChannelName = $context->getChannelNameArg(0);
            if (null !== $auditChannelName) {
                $auditChannel = $this->channelRepository->findByChannelName($auditChannelName);
                if (null !== $auditChannel && !$auditChannel->isFounder((int) $account->getId())) {
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
