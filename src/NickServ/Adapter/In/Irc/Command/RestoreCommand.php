<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Restore\RestoreNick;
use App\NickServ\Application\UseCase\Restore\RestoreNickHandlerInterface;
use App\NickServ\Application\UseCase\Restore\RestoreNickOutcome;
use App\NickServ\Application\UseCase\Restore\RestoreNickResult;

final readonly class RestoreCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(private RestoreNickHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'RESTORE';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 1;
    }

    public function getSyntaxKey(): string
    {
        return 'restore.syntax';
    }

    public function getHelpKey(): string
    {
        return 'restore.help';
    }

    public function getOrder(): int
    {
        return 72;
    }

    public function getShortDescKey(): string
    {
        return 'restore.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): string
    {
        return NickServPermission::RESTORE;
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

        $targetNick = $context->args[0];

        $result = $this->handler->handle(new RestoreNick(
            nickname: $targetNick,
            operatorNick: $sender->nick,
        ));

        return $this->present($context, $result);
    }

    private function present(NickServContext $context, RestoreNickResult $result): CommandOutcome
    {
        switch ($result->outcome) {
            case RestoreNickOutcome::NotRegistered:
                $context->reply('restore.not_registered', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::rejected();
            case RestoreNickOutcome::NotPendingDeletion:
                $context->reply('restore.not_pending_deletion', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::rejected();
            case RestoreNickOutcome::Success:
                $context->reply('restore.success', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::success(new IrcopAuditData(target: $result->nickname ?? ''));
        }
    }
}
