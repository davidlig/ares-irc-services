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
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_shift;
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
        private ?IrcRenderer $renderer = null,
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
            $message = null === $this->renderer
                ? $this->translateWithCommonFallback('unknown_command', ['%command%' => $commandName, '%bot%' => $this->notifier->getNick()])
                : $this->renderer->reply(Role::Error, $this->translateWithCommonFallback('presentation.unknown_command', []), [
                    '%command%' => new Fragment($commandName, Component::Command),
                    '%bot%' => $this->notifier->getNick(),
                    '%help%' => new Fragment('HELP', Component::Command),
                ]);
            $this->notifier->sendMessage($sender->uid, $message, $messageType);

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
            renderer: $this->renderer,
        );

        $this->dispatchToHandler($context, $handler);
    }

    private function dispatchToHandler(OperServContext $context, OperServCommandInterface $handler): void
    {
        $permission = $handler->getRequiredPermission();
        if (null !== $permission && !$context->isAuthorized($permission)) {
            if (OperatorAuthorizationAttribute::ROOT === $permission) {
                $context->reply('error.root_only', [], Role::Error);
            } else {
                $context->replyError(OperatorAuthorizationAttribute::IDENTIFIED === $permission ? 'error.not_identified' : 'error.permission_denied');
            }

            return;
        }

        if (null === $permission && $handler->isOperOnly() && !$context->isAuthorized(null)) {
            $context->replyError('error.oper_only');

            return;
        }

        $validation = CommandArgumentValidator::validatePrefix($handler->getArgumentDefinition(), $context->args);
        if (null !== $validation->syntaxKey) {
            $context->replyError('error.syntax', ['syntax' => $context->trans($validation->syntaxKey)]);

            return;
        }

        $handler->execute($context);
    }

    /** @param array<string, mixed> $parameters */
    private function translateWithCommonFallback(string $key, array $parameters): string
    {
        if ($this->translator instanceof TranslatorBagInterface
            && $this->translator->getCatalogue($this->defaultLanguage)->has($key, 'common')
        ) {
            return $this->translator->trans($key, $parameters, 'common', $this->defaultLanguage);
        }

        return $this->translator->trans($key, $parameters, 'operserv', $this->defaultLanguage);
    }
}
