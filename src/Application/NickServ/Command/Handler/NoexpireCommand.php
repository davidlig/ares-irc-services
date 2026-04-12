<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

use function in_array;
use function strtoupper;

final class NoexpireCommand implements NickServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
    ) {
    }

    public function getName(): string
    {
        return 'NOEXPIRE';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 2;
    }

    public function getSyntaxKey(): string
    {
        return 'noexpire.syntax';
    }

    public function getHelpKey(): string
    {
        return 'noexpire.help';
    }

    public function getOrder(): int
    {
        return 66;
    }

    public function getShortDescKey(): string
    {
        return 'noexpire.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return NickServPermission::NOEXPIRE;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): void
    {
        $targetNick = $context->args[0];
        $action = strtoupper($context->args[1]);

        if (!in_array($action, ['ON', 'OFF'], true)) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return;
        }

        $account = $this->nickRepository->findByNick($targetNick);

        if (null === $account) {
            $context->reply('noexpire.not_registered', ['%nickname%' => $targetNick]);

            return;
        }

        if ($account->isForbidden()) {
            $context->reply('noexpire.forbidden', ['%nickname%' => $targetNick]);

            return;
        }

        if ($account->isSuspended()) {
            $context->reply('noexpire.suspended', ['%nickname%' => $targetNick]);

            return;
        }

        $newValue = 'ON' === $action;
        $account->setNoExpire($newValue);
        $this->nickRepository->save($account);

        $this->auditData = new IrcopAuditData(
            target: $targetNick,
            extra: ['option' => $action],
        );

        $context->reply(
            $newValue ? 'noexpire.success_on' : 'noexpire.success_off',
            ['%nickname%' => $targetNick],
        );
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
