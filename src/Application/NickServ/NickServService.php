<?php

declare(strict_types=1);

namespace App\Application\NickServ;

use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\Security\AuthorizationCheckerInterface;
use App\Application\NickServ\Security\AuthorizationContextInterface;
use App\Application\NickServ\Security\NickServPermission;
use App\Domain\IRC\Network\NetworkUser;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
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
class NickServService
{
    public function __construct(
        private readonly AuthorizationContextInterface $authorizationContext,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly NickServCommandRegistry $commandRegistry,
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NickServNotifierInterface $notifier,
        private readonly TranslatorInterface $translator,
        private readonly PendingVerificationRegistry $pendingVerificationRegistry,
        private readonly string $defaultLanguage = 'en',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Dispatch a command received from a network user.
     *
     * @param string      $rawText Full text of the PRIVMSG (e.g. "REGISTER pass email")
     * @param NetworkUser $sender  The user who sent the message
     */
    public function dispatch(string $rawText, NetworkUser $sender): void
    {
        $parts = preg_split('/\s+/', trim($rawText), -1, PREG_SPLIT_NO_EMPTY);
        $cmdName = strtoupper(array_shift($parts) ?? '');
        $args = $parts;

        if ('' === $cmdName) {
            return;
        }

        $handler = $this->commandRegistry->find($cmdName);

        if (null === $handler) {
            $this->notifier->sendNotice(
                $sender->uid->value,
                $this->translator->trans('unknown_command', ['command' => $cmdName], 'nickserv', $this->defaultLanguage)
            );

            return;
        }

        // Resolve account + language
        $account = $this->nickRepository->findByNick($sender->getNick()->value);
        $language = $account?->getLanguage() ?? $this->defaultLanguage;

        // Build context
        $context = new NickServContext(
            sender: $sender,
            senderAccount: $account,
            command: $cmdName,
            args: $args,
            notifier: $this->notifier,
            translator: $this->translator,
            language: $language,
            registry: $this->commandRegistry,
            pendingVerificationRegistry: $this->pendingVerificationRegistry,
        );

        // Set Security token for this request (cleared at the end)
        $this->authorizationContext->setCurrentUser($sender);

        try {
            // Oper-only guard (via Security voter)
            if ($handler->isOperOnly() && !$this->authorizationChecker->isGranted(NickServPermission::NETWORK_OPER, $context)) {
                $context->reply('error.oper_only');

                return;
            }

            // Required permission guard (e.g. identified owner for SET)
            $requiredPermission = $handler->getRequiredPermission();
            if (null !== $requiredPermission && !$this->authorizationChecker->isGranted($requiredPermission, $context)) {
                $context->reply('error.not_identified');

                return;
            }

            // Minimum args guard
            if (count($args) < $handler->getMinArgs()) {
                $context->reply('error.syntax', [
                    'syntax' => $context->trans($handler->getSyntaxKey()),
                ]);

                return;
            }

            $this->logger->debug(sprintf(
                'NickServ: %s executed %s [args: %d]',
                $sender->getNick()->value,
                $cmdName,
                count($args),
            ));

            $handler->execute($context);
        } finally {
            $this->authorizationContext->clear();
        }
    }
}
