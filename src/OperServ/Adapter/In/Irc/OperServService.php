<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\NickServ\Application\Port\In\UserLanguageQuery;
use App\NickServ\Application\Port\In\UserMessagePreferenceQuery;
use App\OperServ\Application\Port\In\OperatorAuthorizationAttribute;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_shift;
use function count;
use function is_string;
use function preg_split;
use function strtoupper;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/** Routes IRC input and owns only parsing, authorization presentation and output. */
final readonly class OperServService
{
    public function __construct(
        private OperServCommandRegistry $commandRegistry,
        private NickAccountQuery $nickAccounts,
        private UserLanguageQuery $languageResolver,
        private UserMessagePreferenceQuery $messageTypeResolver,
        private OperServNotifierInterface $notifier,
        private TranslatorInterface $translator,
        private ServiceNicknameRegistry $serviceNicks,
        private OperatorAuthorizationQuery $authorization,
        private string $defaultLanguage = 'en',
        private string $defaultTimezone = 'UTC',
    ) {}

    public function dispatch(string $rawText, SenderView $sender): void
    {
        $parts = preg_split('/\s+/', trim($rawText), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $command = array_shift($parts);
        $commandName = strtoupper(is_string($command) ? $command : '');
        /** @var list<string> $args */
        $args = $parts;

        if ('' === $commandName) {
            return;
        }

        $handler = $this->commandRegistry->find($commandName);
        $messageType = $this->messageTypeResolver->prefersPrivateMessages($sender->nick) ? 'PRIVMSG' : 'NOTICE';
        if (null === $handler) {
            $this->notifier->sendMessage(
                $sender->uid,
                $this->translator->trans(
                    'unknown_command',
                    ['%command%' => $commandName, '%bot%' => $this->notifier->getNick()],
                    'operserv',
                    $this->defaultLanguage,
                ),
                $messageType,
            );

            return;
        }

        $account = $this->nickAccounts->findAccountByNick($sender->nick);
        $context = new OperServContext(
            sender: $sender,
            senderAccount: $account,
            command: $commandName,
            args: $args,
            notifier: $this->notifier,
            translator: $this->translator,
            language: $this->languageResolver->resolveFromAccount($sender->uid, $account?->language),
            timezone: null === $account ? $this->defaultTimezone : $account->timezone,
            messageType: $messageType,
            registry: $this->commandRegistry,
            serviceNicks: $this->serviceNicks,
            authorization: $this->authorization,
        );

        $this->dispatchToHandler($context, $handler);
    }

    private function dispatchToHandler(OperServContext $context, OperServCommandInterface $handler): void
    {
        $permission = $handler->getRequiredPermission();
        if (null !== $permission && !$context->isAuthorized($permission)) {
            $context->reply(match ($permission) {
                OperatorAuthorizationAttribute::IDENTIFIED => 'error.not_identified',
                OperatorAuthorizationAttribute::ROOT => 'error.root_only',
                default => 'error.permission_denied',
            });

            return;
        }

        if (null === $permission && $handler->isOperOnly() && !$context->isAuthorized(null)) {
            $context->reply('error.oper_only');

            return;
        }

        if (count($context->args) < $handler->getMinArgs()) {
            $context->reply('error.syntax', ['syntax' => $context->trans($handler->getSyntaxKey())]);

            return;
        }

        $handler->execute($context);
    }
}
