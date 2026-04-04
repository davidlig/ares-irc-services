<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\NickDropService;
use App\Application\NickServ\Service\NickProtectabilityResult;
use App\Application\NickServ\Service\NickProtectabilityStatus;
use App\Application\NickServ\Service\NickTargetValidator;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;

use function strtolower;

final class DropCommand implements NickServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NickTargetValidator $targetValidator,
        private readonly NickDropService $dropService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'DROP';
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
        return 'drop.syntax';
    }

    public function getHelpKey(): string
    {
        return 'drop.help';
    }

    public function getOrder(): int
    {
        return 75;
    }

    public function getShortDescKey(): string
    {
        return 'drop.short';
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
        return NickServPermission::DROP;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $targetNick = $context->args[0];
        $targetNickLower = strtolower($targetNick);
        $senderNickLower = strtolower($context->sender->nick);

        if ($targetNickLower === $senderNickLower) {
            $context->reply('drop.cannot_drop_self');

            return;
        }

        $account = $this->nickRepository->findByNick($targetNick);

        if (null === $account) {
            $context->reply('drop.not_registered', ['%nickname%' => $targetNick]);

            return;
        }

        if ($account->isSuspended()) {
            $context->reply('drop.suspended', ['%nickname%' => $targetNick]);

            return;
        }

        if ($account->isForbidden()) {
            $context->reply('drop.forbidden', ['%nickname%' => $targetNick]);

            return;
        }

        $protectability = $this->targetValidator->validate($targetNick);

        if (!$protectability->isAllowed()) {
            $this->replyProtectabilityError($context, $protectability);

            return;
        }

        $this->dropService->dropNick($account, 'manual', $context->sender->nick);

        $this->auditData = new IrcopAuditData(target: $targetNick);

        $context->reply('drop.success', ['%nickname%' => $targetNick]);
    }

    private function replyProtectabilityError(NickServContext $context, NickProtectabilityResult $result): void
    {
        $nickname = $result->nickname;

        match ($result->status) {
            NickProtectabilityStatus::IsRoot => $context->reply('drop.cannot_drop_root', ['%nickname%' => $nickname]),
            NickProtectabilityStatus::IsIrcop => $context->reply('drop.cannot_drop_oper', ['%nickname%' => $nickname]),
            NickProtectabilityStatus::IsService => $context->reply('drop.cannot_drop_service', ['%nickname%' => $nickname]),
        };
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
