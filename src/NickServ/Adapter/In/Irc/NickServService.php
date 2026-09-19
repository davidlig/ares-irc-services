<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Irc\Application\PublishedEvent\CommandExecutedEvent;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Port\In\UserLanguageQuery;
use App\NickServ\Application\Port\In\UserMessagePreferenceQuery;
use App\NickServ\Application\Port\Out\AuthorizationCheckerInterface;
use App\NickServ\Application\Port\Out\AuthorizationContextInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\Shared\Application\Port\EventBusInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

use function assert;
use function count;
use function in_array;
use function is_string;
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
        private AuthorizationContextInterface $authorizationContext,
        private AuthorizationCheckerInterface $authorizationChecker,
        private NickServCommandRegistry $commandRegistry,
        private RegisteredNickRepositoryInterface $nickRepository,
        private UserLanguageQuery $languageResolver,
        private NickServNotifierInterface $notifier,
        private UserMessagePreferenceQuery $messageTypeResolver,
        private TranslatorInterface $translator,
        private PendingVerificationRegistry $pendingVerificationRegistry,
        private RecoveryTokenRegistry $recoveryTokenRegistry,
        private ServiceNicknameRegistry $serviceNicks,
        private EventBusInterface $eventDispatcher,
        private string $defaultLanguage = 'en',
        private string $defaultTimezone = 'UTC',
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Dispatch a command received from a network user.
     *
     * @param string     $rawText Full text of the PRIVMSG (e.g. "REGISTER pass email")
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
            $this->replyUnknownCommand($sender, $cmdName);

            return;
        }

        $account = $this->nickRepository->findByNick($sender->nick);
        $language = $this->languageResolver->resolveFromAccount($sender->uid, $account?->getLanguage());
        $timezone = $account?->getTimezone() ?? $this->defaultTimezone;
        $messageType = $this->messageTypeResolver->prefersPrivateMessages($sender->nick) ? 'PRIVMSG' : 'NOTICE';

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
            serviceNicks: $this->serviceNicks,
        );

        $this->authorizationContext->setCurrentUser($sender->uid, $sender->isIdentified, $sender->isOper);

        try {
            $error = $this->checkCommandPermissions($context, $handler);
            if (null !== $error) {
                return;
            }

            $this->executeHandlerAfterValidation($context, $handler, $args);
        } finally {
            $this->authorizationContext->clear();
        }
    }

    private function replyUnknownCommand(SenderView $sender, string $cmdName): void
    {
        $messageType = $this->messageTypeResolver->prefersPrivateMessages($sender->nick) ? 'PRIVMSG' : 'NOTICE';
        $this->notifier->sendMessage(
            $sender->uid,
            $this->translator->trans('unknown_command', ['%command%' => $cmdName, '%bot%' => $this->notifier->getNick()], 'nickserv', $this->defaultLanguage),
            $messageType
        );
    }

    private function checkCommandPermissions(NickServContext $context, NickServCommandInterface $handler): ?string
    {
        $requiredPermission = $handler->getRequiredPermission();
        if (null !== $requiredPermission && !$this->authorizationChecker->isGranted($requiredPermission, $context)) {
            if ('IDENTIFIED' === $requiredPermission) {
                $context->reply('error.not_identified');
            } else {
                $context->reply('error.permission_denied');
            }

            return 'denied';
        }

        return null;
    }

    /**
     * @param list<string> $args
     */
    private function executeHandlerAfterValidation(NickServContext $context, NickServCommandInterface $handler, array $args): void
    {
        $sender = $context->sender;
        assert(null !== $sender);

        if (count($args) < $handler->getMinArgs()) {
            $context->reply('error.syntax', [
                'syntax' => $context->trans($handler->getSyntaxKey()),
            ]);

            return;
        }

        if (null !== $context->senderAccount && $context->senderAccount->isPendingDeletion() && !in_array($handler->getName(), ['REGISTER', 'INFO', 'RESTORE', 'DROP'], true)) {
            $context->reply('drop.pending_deletion', ['%nickname%' => $sender->nick]);

            return;
        }

        $requiredPermission = $handler->getRequiredPermission();

        $this->logger->debug(sprintf(
            'NickServ: %s executed %s [args: %d]',
            $sender->nick,
            $context->command,
            count($context->args),
        ));

        $result = $handler->execute($context);
        $this->eventDispatcher->dispatch(new CommandExecutedEvent(
            command: $handler,
            serviceName: $this->notifier->getServiceKey(),
            operatorNick: $sender->nick,
            commandName: $context->command,
            permission: $requiredPermission,
            outcome: $result instanceof CommandOutcome ? $result : null,
        ));
    }
}
