<?php

declare(strict_types=1);

namespace App\Application\NickServ;

use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\Security\AuthorizationCheckerInterface;
use App\Application\NickServ\Security\AuthorizationContextInterface;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\NickServ\UserMessageTypeResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;
use function sprintf;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Routes a raw command string (from a PRIVMSG to NickServ) to the correct
 * command handler. Resolves the user's language preference and builds the
 * NickServContext that every command handler receives.
 */
final readonly class NickServService
{
    public function __construct(
        private readonly AuthorizationContextInterface $authorizationContext,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly NickServCommandRegistry $commandRegistry,
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NickServNotifierInterface $notifier,
        private readonly UserMessageTypeResolver $messageTypeResolver,
        private readonly TranslatorInterface $translator,
        private readonly PendingVerificationRegistry $pendingVerificationRegistry,
        private readonly RecoveryTokenRegistry $recoveryTokenRegistry,
        private readonly string $defaultLanguage = 'en',
        private readonly string $defaultTimezone = 'UTC',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Dispatch a command received from a network user.
     *
     * @param string     $rawText Full text of the PRIVMSG (e.g. "REGISTER pass email")
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
                $this->translator->trans('unknown_command', ['command' => $cmdName], 'nickserv', $this->defaultLanguage),
                $messageType
            );

            return;
        }

        $account = $this->nickRepository->findByNick($sender->nick);
        $language = $account?->getLanguage() ?? $this->defaultLanguage;
        $timezone = $account?->getTimezone() ?? $this->defaultTimezone;
        $messageType = $this->messageTypeResolver->resolve($sender);

        $context = new NickServContext(
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
            pendingVerificationRegistry: $this->pendingVerificationRegistry,
            recoveryTokenRegistry: $this->recoveryTokenRegistry,
        );

        $this->authorizationContext->setCurrentUser($sender);

        try {
            if ($handler->isOperOnly() && !$this->authorizationChecker->isGranted(NickServPermission::NETWORK_OPER, $context)) {
                $context->reply('error.oper_only');

                return;
            }

            $requiredPermission = $handler->getRequiredPermission();
            if (null !== $requiredPermission && !$this->authorizationChecker->isGranted($requiredPermission, $context)) {
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
                'NickServ: %s executed %s [args: %d]',
                $sender->nick,
                $cmdName,
                count($args),
            ));

            $handler->execute($context);
        } finally {
            $this->authorizationContext->clear();
        }
    }
}
