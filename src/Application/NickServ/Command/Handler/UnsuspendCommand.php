<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

final class UnsuspendCommand implements NickServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
    ) {
    }

    public function getName(): string
    {
        return 'UNSUSPEND';
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
        return 'unsuspend.syntax';
    }

    public function getHelpKey(): string
    {
        return 'unsuspend.help';
    }

    public function getOrder(): int
    {
        return 71;
    }

    public function getShortDescKey(): string
    {
        return 'unsuspend.short';
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
        return NickServPermission::SUSPEND;
    }

    public function execute(NickServContext $context): void
    {
        $targetNick = $context->args[0];

        $account = $this->nickRepository->findByNick($targetNick);

        if (null === $account) {
            $context->reply('unsuspend.not_registered', ['%nickname%' => $targetNick]);

            return;
        }

        if (!$account->isSuspended()) {
            $context->reply('unsuspend.not_suspended', ['%nickname%' => $targetNick]);

            return;
        }

        $account->unsuspend();
        $this->nickRepository->save($account);

        $this->auditData = new IrcopAuditData(
            target: $targetNick,
        );

        $context->reply('unsuspend.success', ['%nickname%' => $targetNick]);
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
