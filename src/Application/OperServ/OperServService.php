<?php

declare(strict_types=1);

namespace App\Application\OperServ;

use App\Application\Command\CommandOutcome;
use App\Application\Event\CommandExecutedEvent;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\Port\Out\ServiceUserPreferences;
use App\Application\Port\EventBusInterface;
use App\Application\Port\TranslationInterface;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Application\Port\Out\AuthorizationCheckerInterface;
use App\NickServ\Application\Port\Out\AuthorizationContextInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function count;
use function is_string;
use function sprintf;

use const PREG_SPLIT_NO_EMPTY;

final readonly class OperServService
{
    public function __construct(
        private OperServCommandRegistry $commandRegistry,
        private RegisteredNickRepositoryInterface $nickRepository,
        private ServiceUserPreferences $languageResolver,
        private OperServNotifierInterface $notifier,
        private ServiceUserPreferences $messageTypeResolver,
        private TranslationInterface $translator,
        private IrcopAccessHelper $accessHelper,
        private ServiceNicknameRegistry $serviceNicks,
        private AuthorizationContextInterface $authorizationContext,
        private AuthorizationCheckerInterface $authorizationChecker,
        private EventBusInterface $eventDispatcher,
        private string $defaultLanguage = 'en',
        private string $defaultTimezone = 'UTC',
        private LoggerInterface $logger = new NullLogger(),
    ) {}

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
            $messageType = $this->messageTypeResolver->prefersPrivateMessages($sender->nick) ? 'PRIVMSG' : 'NOTICE';
            $this->notifier->sendMessage(
                $sender->uid,
                $this->translator->trans('unknown_command', ['%command%' => $cmdName, '%bot%' => $this->notifier->getNick()], 'operserv', $this->defaultLanguage),
                $messageType
            );

            return;
        }

        $account = $this->nickRepository->findByNick($sender->nick);
        $language = $this->languageResolver->languageFor($sender->uid, $sender->nick, $account?->getLanguage());
        $timezone = $account?->getTimezone() ?? $this->defaultTimezone;
        $messageType = $this->messageTypeResolver->prefersPrivateMessages($sender->nick) ? 'PRIVMSG' : 'NOTICE';

        $context = new OperServContext(
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
            accessHelper: $this->accessHelper,
            serviceNicks: $this->serviceNicks,
        );

        $this->authorizationContext->setCurrentUser($sender->uid, $sender->isIdentified, $sender->isOper);
        $this->dispatchToHandler($context, $handler, $sender, $cmdName);
    }

    private function dispatchToHandler(OperServContext $context, Command\OperServCommandInterface $handler, SenderView $sender, string $cmdName): void
    {
        try {
            $requiredPermission = $handler->getRequiredPermission();
            if (null !== $requiredPermission) {
                $isGranted = $this->authorizationChecker->isGranted($requiredPermission, $context);
                $this->logger->debug('OperServ authorization check', [
                    'nick' => $sender->nick,
                    'isIdentified' => $sender->isIdentified,
                    'isOper' => $sender->isOper,
                    'permission' => $requiredPermission,
                    'isGranted' => $isGranted,
                ]);
                if (!$isGranted) {
                    $context->reply('IDENTIFIED' === $requiredPermission ? 'error.not_identified' : 'error.permission_denied');

                    return;
                }
            }

            if ($handler->isOperOnly() && !$sender->isOper && !$this->accessHelper->isIrcop($context->senderAccount?->getId() ?? 0, strtolower($sender->nick))) {
                $context->reply('error.oper_only');

                return;
            }

            if (count($context->args) < $handler->getMinArgs()) {
                $context->reply('error.syntax', [
                    '%syntax%' => $context->trans($handler->getSyntaxKey()),
                ]);

                return;
            }

            $this->logger->debug(sprintf(
                'OperServ: %s executed %s [args: %d]',
                $sender->nick,
                $cmdName,
                count($context->args),
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
        } finally {
            $this->authorizationContext->clear();
        }
    }
}
