<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\NickDropService;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

final class RestoreCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NickDropService $dropService,
    ) {}

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

    public function getRequiredPermission(): ?string
    {
        return NickServPermission::RESTORE;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): CommandOutcome
    {
        if (null === $context->sender) {
            return CommandOutcome::rejected();
        }

        $targetNick = $context->args[0];
        $account = $this->nickRepository->findByNick($targetNick);

        if (null === $account) {
            $context->reply('restore.not_registered', ['%nickname%' => $targetNick]);

            return CommandOutcome::rejected();
        }

        if (!$account->isPendingDeletion()) {
            $context->reply('restore.not_pending_deletion', ['%nickname%' => $targetNick]);

            return CommandOutcome::rejected();
        }

        $this->dropService->restoreNick($account, $context->sender->nick);
        $context->reply('restore.success', ['%nickname%' => $targetNick]);

        return CommandOutcome::success(new IrcopAuditData(target: $targetNick));
    }
}
