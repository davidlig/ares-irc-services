<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Saregister\Saregister;
use App\NickServ\Application\UseCase\Saregister\SaregisterHandlerInterface;
use App\NickServ\Application\UseCase\Saregister\SaregisterOutcome;
use App\NickServ\Application\UseCase\Saregister\SaregisterResult;
use DateTimeImmutable;

/**
 * SAREGISTER <nickname> <password> <email>; IRC parsing and presentation only.
 *
 * Registers a nickname for another user at staff request: no e-mail confirmation
 * and no registration throttle. The legacy Anope command of the same name.
 */
final readonly class SaregisterCommand implements NickServCommandInterface
{
    public function __construct(private SaregisterHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'SAREGISTER';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 3;
    }

    public function getSyntaxKey(): string
    {
        return 'saregister.syntax';
    }

    public function getHelpKey(): string
    {
        return 'saregister.help';
    }

    public function getOrder(): int
    {
        return 73;
    }

    public function getShortDescKey(): string
    {
        return 'saregister.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): string
    {
        return NickServPermission::SAREGISTER;
    }

    public function getExampleKey(): string
    {
        return 'saregister.example';
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): CommandOutcome
    {
        $sender = $context->sender;
        if (null === $sender) {
            return CommandOutcome::rejected();
        }

        $result = $this->handler->handle(new Saregister(
            nickname: $context->args[0],
            password: $context->args[1],
            email: $context->args[2],
            language: $context->getLanguage(),
            actorNickname: $sender->nick,
            actorAccountId: $context->getSenderAccountId(),
            occurredAt: new DateTimeImmutable(),
        ));

        return $this->present($context, $result);
    }

    private function present(NickServContext $context, SaregisterResult $result): CommandOutcome
    {
        switch ($result->outcome) {
            case SaregisterOutcome::Registered:
                $context->reply('saregister.done', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::success();
            case SaregisterOutcome::InvalidEmail:
                $context->reply('register.invalid_email');

                return CommandOutcome::rejected();
            case SaregisterOutcome::AlreadyRegistered:
                $context->reply('register.already_registered', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::rejected();
            case SaregisterOutcome::AlreadyPending:
                $context->reply('register.already_pending', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::rejected();
            case SaregisterOutcome::Forbidden:
                $context->reply('register.forbidden', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::rejected();
            case SaregisterOutcome::PendingDeletion:
                $context->reply('register.pending_deletion', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::rejected();
        }
    }
}
